<?php
/**
 * 모임 / 체험단 승인 / 거절 API
 * POST /api/meeting/approve.php
 *
 * 변경사항 (체험단 도입):
 * - meeting_type = '체험단'이면 7번 블록(그룹 채팅방 자동생성) 완전 스킵
 * - 체험단 채팅은 1:1만 사용 (create_room.php direct 버튼으로 진입)
 * - 승인 상태 / 정원 처리 / 트랜잭션 / 푸시 / WebSocket emit 기존 그대로
 */
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/push_emit.php';
require_once '../utils/socket_emit.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 본인 확인 (body의 host_id를 신뢰하지 않음 — 위조 방지)
$host_id = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['participant_id']) || empty($input['action'])) {
    sendError('participant_id, action이 필요합니다');
}

$participant_id = intval($input['participant_id']);
$action         = trim($input['action']); // 'approve' or 'reject'

if (!in_array($action, ['approve', 'reject'])) {
    sendError('action은 approve 또는 reject여야 합니다');
}

$database = new Database();
$db = $database->getConnection();

try {
    $db->beginTransaction();

    // ── 1. 신청자 정보 조회 (meeting_type 포함) ──────────────
    $participant_query = "SELECT
                            mp.participant_id,
                            mp.user_id,
                            mp.meeting_id,
                            mp.participation_status,
                            m.meeting_host_id,
                            m.meeting_type,
                            m.meeting_current_participants,
                            m.meeting_max_participants
                          FROM meeting_participants mp
                          JOIN meetings m ON mp.meeting_id = m.meeting_id
                          WHERE mp.participant_id = :participant_id";

    $participant_stmt = $db->prepare($participant_query);
    $participant_stmt->bindParam(':participant_id', $participant_id);
    $participant_stmt->execute();

    if ($participant_stmt->rowCount() === 0) {
        $db->rollBack();
        sendError('신청 정보를 찾을 수 없습니다', 404);
    }

    $participant  = $participant_stmt->fetch(PDO::FETCH_ASSOC);
    $meeting_type = $participant['meeting_type'];

    // ── 2. 호스트 권한 확인 (기존 그대로) ───────────────────
    if ($participant['meeting_host_id'] != $host_id) {
        $db->rollBack();
        sendError('호스트만 승인/거절할 수 있습니다', 403);
    }

    // ── 3. 상태 확인 (기존 그대로) ──────────────────────────
    if ($participant['participation_status'] !== '대기') {
        $db->rollBack();
        $current_status = $participant['participation_status'];
        sendError("이미 처리된 신청입니다 (현재 상태: {$current_status})");
    }

    // ── 4. 승인 시 정원 확인 (기존 그대로) ──────────────────
    if ($action === 'approve') {
        if ($participant['meeting_current_participants'] >= $participant['meeting_max_participants']) {
            $db->rollBack();
            sendError('모임 정원이 가득 찼습니다');
        }
    }

    // ── 5. 상태 업데이트 (기존 그대로) ──────────────────────
    $new_status = ($action === 'approve') ? '승인' : '거절';

    $db->prepare(
        "UPDATE meeting_participants
         SET participation_status = :status, approved_at = NOW(), approved_by = :approved_by
         WHERE participant_id = :participant_id"
    )->execute([
        ':status'         => $new_status,
        ':approved_by'    => $host_id,
        ':participant_id' => $participant_id,
    ]);

    // ── 6. 승인 시 현재 인원 증가 + 모집완료 처리 (기존 그대로) ─
    $meeting_current_participants = $participant['meeting_current_participants'];

    if ($action === 'approve') {
        $db->prepare(
            "UPDATE meetings SET meeting_current_participants = meeting_current_participants + 1
             WHERE meeting_id = :meeting_id"
        )->execute([':meeting_id' => $participant['meeting_id']]);

        $meeting_current_participants += 1;

        if ($meeting_current_participants >= $participant['meeting_max_participants']) {
            $db->prepare(
                "UPDATE meetings SET meeting_status = '모집완료' WHERE meeting_id = :meeting_id"
            )->execute([':meeting_id' => $participant['meeting_id']]);
        }
    }

    // ── 7. 채팅방 처리 ───────────────────────────────────────
    // 체험단이면 그룹 채팅방 자동생성 완전 스킵
    // 체험단 채팅은 1:1만 (CampaignApplicantListActivity의 채팅하기 버튼 → create_room.php direct)
    $room_id          = null;
    $room_name        = null;
    $new_room_created = false;
    $member_added     = false;
    $approved_uid     = $participant['user_id'];
    $meeting_id_val   = $participant['meeting_id'];

    // "OO님이 초대되었습니다" 시스템 메시지 실시간 broadcast용
    // (kick.php처럼 message 이벤트로 쏴야 ChatFragment 목록의 last_message가 즉시 갱신됨)
    $invite_msg_id         = null;
    $invite_msg_content    = null;
    $invite_msg_created_at = null;

    if ($action === 'approve' && $meeting_type === '일반') {
        // 일반 모임만 그룹 채팅방 자동생성 (기존 로직 그대로)
        $nick_stmt = $db->prepare("SELECT user_nickname FROM users WHERE user_id = :uid");
        $nick_stmt->bindParam(':uid', $approved_uid);
        $nick_stmt->execute();
        $approved_user     = $nick_stmt->fetch(PDO::FETCH_ASSOC);
        $approved_nickname = $approved_user ? $approved_user['user_nickname'] : '참여자';

        $room_check = $db->prepare(
            "SELECT room_id, room_name FROM chat_rooms WHERE meeting_id = :mid LIMIT 1"
        );
        $room_check->bindParam(':mid', $meeting_id_val);
        $room_check->execute();
        $existing_room = $room_check->fetch(PDO::FETCH_ASSOC);

        if (!$existing_room) {
            // CASE A: 채팅방 없음 → 새로 생성
            $title_stmt = $db->prepare(
                "SELECT m.meeting_title, m.meeting_image, u.user_profile_image
                 FROM meetings m JOIN users u ON m.meeting_host_id = u.user_id
                 WHERE m.meeting_id = :mid"
            );
            $title_stmt->bindParam(':mid', $meeting_id_val);
            $title_stmt->execute();
            $meeting_row = $title_stmt->fetch(PDO::FETCH_ASSOC);

            $room_name  = $meeting_row ? $meeting_row['meeting_title'] : '모임 채팅';
            $room_image = $meeting_row
                ? ($meeting_row['meeting_image'] ?: $meeting_row['user_profile_image'])
                : null;

            $create_room = $db->prepare(
                "INSERT INTO chat_rooms (room_type, room_name, room_image, meeting_id, owner_id, created_at)
                 VALUES ('group', :rname, :rimage, :mid, :owner, NOW())"
            );
            $create_room->execute([':rname' => $room_name, ':rimage' => $room_image,
                                   ':mid'   => $meeting_id_val, ':owner' => $host_id]);
            $room_id          = (int)$db->lastInsertId();
            $new_room_created = true;

            // 방장 + 신청자 멤버 추가
            $add_member_stmt = $db->prepare(
                "INSERT INTO chat_room_members (room_id, user_id, last_read_at, joined_at)
                 VALUES (:rid, :uid, NOW(), NOW())"
            );
            foreach ([$host_id, $approved_uid] as $member_uid) {
                $add_member_stmt->execute([':rid' => $room_id, ':uid' => $member_uid]);
            }

            // 시스템 메시지 2개 (마지막 초대 메시지 id는 broadcast용으로 보관)
            $invite_msg_content = $approved_nickname . '님이 초대되었습니다.';
            foreach (['채팅방이 생성되었습니다.', $invite_msg_content] as $sys_msg) {
                $db->prepare(
                    "INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
                     VALUES (:rid, NULL, 'system', :content, NOW())"
                )->execute([':rid' => $room_id, ':content' => $sys_msg]);
                $invite_msg_id = (int)$db->lastInsertId();
            }

            $db->prepare(
                "UPDATE chat_rooms SET last_message = :lm, last_msg_time = NOW() WHERE room_id = :rid"
            )->execute([':lm' => $invite_msg_content, ':rid' => $room_id]);

        } else {
            // CASE B: 채팅방 있음 → 멤버만 추가
            $room_id   = (int)$existing_room['room_id'];
            $room_name = $existing_room['room_name'];

            $mem_check = $db->prepare(
                "SELECT id FROM chat_room_members WHERE room_id = :rid AND user_id = :uid"
            );
            $mem_check->execute([':rid' => $room_id, ':uid' => $approved_uid]);

            if (!$mem_check->fetch()) {
                $db->prepare(
                    "INSERT INTO chat_room_members (room_id, user_id, last_read_at, joined_at)
                     VALUES (:rid, :uid, NOW(), NOW())"
                )->execute([':rid' => $room_id, ':uid' => $approved_uid]);

                $invite_msg_content = $approved_nickname . '님이 초대되었습니다.';
                $db->prepare(
                    "INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
                     VALUES (:rid, NULL, 'system', :content, NOW())"
                )->execute([':rid' => $room_id, ':content' => $invite_msg_content]);
                $invite_msg_id = (int)$db->lastInsertId();

                $db->prepare(
                    "UPDATE chat_rooms SET last_message = :lm, last_msg_time = NOW() WHERE room_id = :rid"
                )->execute([':lm' => $invite_msg_content, ':rid' => $room_id]);

                $member_added = true;
            }
        }

        // broadcast용 초대 메시지 created_at 조회
        if ($invite_msg_id) {
            $msg_time_stmt = $db->prepare("SELECT created_at FROM chat_messages WHERE message_id = :mid");
            $msg_time_stmt->execute([':mid' => $invite_msg_id]);
            $msg_time_row = $msg_time_stmt->fetch(PDO::FETCH_ASSOC);
            $invite_msg_created_at = $msg_time_row ? $msg_time_row['created_at'] : date('Y-m-d H:i:s');
        }
    }
    // 체험단(meeting_type === '체험단')이면 위 블록 전체 스킵

    $db->commit();

    // ── 푸시 알림 (기존 그대로) ──────────────────────────────
    if ($action === 'approve') {
        push_to_user($participant['user_id'], 'meeting_approved', '모임 참여 승인',
            '모임 참여가 승인되었어요', ['meeting_id' => $participant['meeting_id']]);
    } else {
        push_to_user($participant['user_id'], 'meeting_rejected', '모임 참여 거절',
            '참여 신청이 거절되었어요', ['meeting_id' => $participant['meeting_id']]);
    }

    // ── 참여 상태 실시간 반영 (일반+체험단, 승인/거절 공통) ──
    // 신청자가 상세 화면을 열어둔 상태에서도 버튼("승인 대기 중" 등)이
    // 즉시 갱신되도록 user_{id} 채널로 상태 변경 이벤트를 보낸다.
    emit_to_user($approved_uid, 'participation_updated', [
        'meeting_id' => (int)$meeting_id_val,
        'status'     => $new_status,
    ]);

    // ── WebSocket emit (일반 모임만) ─────────────────────────
    if ($action === 'approve' && $meeting_type === '일반' && $room_id && $room_name) {
        if ($new_room_created) {
            emit_to_user($approved_uid, 'room_joined', [
                'room_id'   => $room_id,
                'room_type' => 'group',
                'room_name' => $room_name
            ]);
            // 호스트에게도 통보 — 새 방은 message 이벤트만으로는 목록에 추가되지
            // 않으므로(ChatFragment는 아는 방의 last_message만 갱신) 목록 재조회 유도
            emit_to_user($host_id, 'room_joined', [
                'room_id'   => $room_id,
                'room_type' => 'group',
                'room_name' => $room_name
            ]);
        } elseif ($member_added) {
            emit_to_user($approved_uid, 'room_joined', [
                'room_id'   => $room_id,
                'room_type' => 'group',
                'room_name' => $room_name
            ]);
            emit_to_room($room_id, 'member_joined', [
                'room_id'  => $room_id,
                'user_id'  => $approved_uid,
                'nickname' => $approved_nickname ?? '참여자'
            ]);
        }

        // "OO님이 초대되었습니다" 시스템 메시지를 message 이벤트로 broadcast
        // (kick.php의 강퇴 메시지와 동일 패턴 — 열려있는 채팅 목록의
        //  last_message와 채팅방 화면에 실시간 반영됨)
        if ($invite_msg_id) {
            emit_to_room($room_id, 'message', [
                'room_id'    => $room_id,
                'message_id' => $invite_msg_id,
                'msg_type'   => 'system',
                'sender_id'  => null,
                'content'    => $invite_msg_content,
                'created_at' => $invite_msg_created_at,
            ]);
        }
    }

    $message = ($action === 'approve') ? '참여 신청을 승인했습니다' : '참여 신청을 거절했습니다';

    $response_data = [
        'participant_id'       => (int)$participant_id,
        'participation_status' => $new_status,
        'meeting_type'         => $meeting_type,
    ];

    if ($action === 'approve') {
        $response_data['meeting_current_participants'] = (int)$meeting_current_participants;
        if ($meeting_type === '일반') {
            $response_data['room_id'] = $room_id;
        }
    }

    sendSuccess($message, $response_data);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('승인/거절 처리 실패: ' . $e->getMessage(), 500);
}
?>