<?php
/**
 * 모임 신청 승인/거절 API
 * POST /api/meeting/approve.php
 * 
 * 요청:
 * {
 *   "participant_id": 5,
 *   "action": "approve",  // "approve" 또는 "reject"
 *   "host_id": 1
 * }
 * 
 * 승인 응답:
 * {
 *   "success": true,
 *   "message": "참여 신청을 승인했습니다",
 *   "data": {
 *     "participant_id": 5,
 *     "participation_status": "승인",
 *     "meeting_current_participants": 3
 *   }
 * }
 * 
 * 거절 응답:
 * {
 *   "success": true,
 *   "message": "참여 신청을 거절했습니다",
 *   "data": {
 *     "participant_id": 5,
 *     "participation_status": "거절"
 *   }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// 필수 필드 확인
if (empty($input['participant_id']) || empty($input['action']) || empty($input['host_id'])) {
    sendError('participant_id, action, host_id는 필수입니다');
}

$participant_id = intval($input['participant_id']);
$action = trim($input['action']);
$host_id = intval($input['host_id']);

// action 검증
if (!in_array($action, ['approve', 'reject'])) {
    sendError('action은 "approve" 또는 "reject"만 가능합니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

try {
    // 트랜잭션 시작
    $db->beginTransaction();
    
    // 1. 참여 신청 정보 조회
    $query = "SELECT 
                mp.participant_id,
                mp.meeting_id,
                mp.user_id,
                mp.participation_status,
                m.meeting_host_id,
                m.meeting_max_participants,
                m.meeting_current_participants
              FROM meeting_participants mp
              JOIN meetings m ON mp.meeting_id = m.meeting_id
              WHERE mp.participant_id = :participant_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':participant_id', $participant_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        sendError('존재하지 않는 참여 신청입니다', 404);
    }
    
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. 호스트 권한 확인
    if ($participant['meeting_host_id'] != $host_id) {
        $db->rollBack();
        sendError('호스트만 승인/거절할 수 있습니다', 403);
    }
    
    // 3. 상태 확인
    if ($participant['participation_status'] !== '대기') {
        $db->rollBack();
        $current_status = $participant['participation_status'];
        sendError("이미 처리된 신청입니다 (현재 상태: {$current_status})");
    }
    
    // 4. 승인인 경우 정원 확인
    if ($action === 'approve') {
        if ($participant['meeting_current_participants'] >= $participant['meeting_max_participants']) {
            $db->rollBack();
            sendError('모임 정원이 가득 찼습니다');
        }
    }
    
    // 5. 상태 업데이트
    $new_status = ($action === 'approve') ? '승인' : '거절';
    
    $update_query = "UPDATE meeting_participants 
                     SET 
                        participation_status = :status,
                        approved_at = NOW(),
                        approved_by = :approved_by
                     WHERE participant_id = :participant_id";
    
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':status', $new_status);
    $update_stmt->bindParam(':approved_by', $host_id);
    $update_stmt->bindParam(':participant_id', $participant_id);
    $update_stmt->execute();
    
    // 6. 승인인 경우 모임 현재 인원 증가
    $meeting_current_participants = $participant['meeting_current_participants'];
    
    if ($action === 'approve') {
        $update_meeting_query = "UPDATE meetings 
                                 SET meeting_current_participants = meeting_current_participants + 1
                                 WHERE meeting_id = :meeting_id";
        
        $update_meeting_stmt = $db->prepare($update_meeting_query);
        $update_meeting_stmt->bindParam(':meeting_id', $participant['meeting_id']);
        $update_meeting_stmt->execute();
        
        $meeting_current_participants += 1;
        
        // 정원이 가득 찬 경우 모집 완료로 변경
        if ($meeting_current_participants >= $participant['meeting_max_participants']) {
            $complete_query = "UPDATE meetings 
                              SET meeting_status = '모집완료'
                              WHERE meeting_id = :meeting_id";
            
            $complete_stmt = $db->prepare($complete_query);
            $complete_stmt->bindParam(':meeting_id', $participant['meeting_id']);
            $complete_stmt->execute();
        }
    }

    // 7. 채팅방 자동 생성 or 멤버 추가
    $room_id = null;

    if ($action === 'approve') {
        $meeting_id   = $participant['meeting_id'];
        $approved_uid = $participant['user_id'];

        // 7-1. 승인된 사용자 닉네임 조회
        $nick_stmt = $db->prepare("SELECT user_nickname FROM users WHERE user_id = :uid");
        $nick_stmt->bindParam(':uid', $approved_uid);
        $nick_stmt->execute();
        $approved_user     = $nick_stmt->fetch(PDO::FETCH_ASSOC);
        $approved_nickname = $approved_user ? $approved_user['user_nickname'] : '참여자';

        // 7-2. 이 모임의 채팅방이 이미 있는지 확인
        $room_check = $db->prepare("SELECT room_id FROM chat_rooms WHERE meeting_id = :mid LIMIT 1");
        $room_check->bindParam(':mid', $meeting_id);
        $room_check->execute();
        $existing_room = $room_check->fetch(PDO::FETCH_ASSOC);

        if (!$existing_room) {
            // ── CASE A: 채팅방 없음 → 새로 생성 ──────────────────────
            
            // 모임제목 + 이미지 조회 (없으면 방장 프로필 이미지로)
            $title_stmt = $db->prepare(
                "SELECT m.meeting_title, m.meeting_image, u.user_profile_image
                 FROM meetings m
                 JOIN users u ON m.meeting_host_id = u.user_id
                 WHERE m.meeting_id = :mid"
            );
            $title_stmt->bindParam(':mid', $meeting_id);
            $title_stmt->execute();
            $meeting_row = $title_stmt->fetch(PDO::FETCH_ASSOC);

            $room_name  = $meeting_row ? $meeting_row['meeting_title'] : '모임 채팅';
             // meeting_image가 있으면 그걸, 없으면 방장 프로필 이미지
            $room_image = $meeting_row
                ? ($meeting_row['meeting_image'] ?: $meeting_row['user_profile_image'])
                : null;

            // chat_rooms 생성
            $create_room = $db->prepare(
                "INSERT INTO chat_rooms (room_type, room_name, room_image, meeting_id, owner_id, created_at)
                 VALUES ('group', :rname, :rimage, :mid, :owner, NOW())"
            );
            $create_room->bindParam(':rname',  $room_name);
            $create_room->bindParam(':rimage', $room_image);
            $create_room->bindParam(':mid',    $meeting_id);
            $create_room->bindParam(':owner',  $host_id);
            $create_room->execute();
            $room_id = (int)$db->lastInsertId();
            
            // 방장 멤버 추가
            $add_host = $db->prepare(
                "INSERT INTO chat_room_members (room_id, user_id, last_read_at, joined_at)
                 VALUES (:rid, :uid, NOW(), NOW())"
            );
            $add_host->bindParam(':rid', $room_id);
            $add_host->bindParam(':uid', $host_id);
            $add_host->execute();

            // 신청자 멤버 추가
            $add_member = $db->prepare(
                "INSERT INTO chat_room_members (room_id, user_id, last_read_at, joined_at)
                 VALUES (:rid, :uid, NOW(), NOW())"
            );
            $add_member->bindParam(':rid', $room_id);
            $add_member->bindParam(':uid', $approved_uid);
            $add_member->execute();

            // 시스템 메시지 1: 방 생성 안내
            $sys1 = '채팅방이 생성되었습니다.';
            $msg1 = $db->prepare(
                "INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
                 VALUES (:rid, NULL, 'system', :content, NOW())"
            );
            $msg1->bindParam(':rid', $room_id);
            $msg1->bindParam(':content', $sys1);
            $msg1->execute();

            // 시스템 메시지 2: 신청자 초대 안내
            $sys2 = $approved_nickname . '님이 초대되었습니다.';
            $msg2 = $db->prepare(
                "INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
                 VALUES (:rid, NULL, 'system', :content, NOW())"
            );
            $msg2->bindParam(':rid', $room_id);
            $msg2->bindParam(':content', $sys2);
            $msg2->execute();

            // chat_rooms 마지막 메시지 업데이트
            $upd = $db->prepare(
                "UPDATE chat_rooms SET last_message = :lm, last_msg_time = NOW() WHERE room_id = :rid"
            );
            $upd->bindParam(':lm',  $sys2);
            $upd->bindParam(':rid', $room_id);
            $upd->execute();

        } else {
            // ── CASE B: 채팅방 이미 있음 → 멤버만 추가 ──────────────

            $room_id = (int)$existing_room['room_id'];

            // 이미 멤버인지 확인 (중복 방지)
            $mem_check = $db->prepare(
                "SELECT id FROM chat_room_members WHERE room_id = :rid AND user_id = :uid"
            );
            $mem_check->bindParam(':rid', $room_id);
            $mem_check->bindParam(':uid', $approved_uid);
            $mem_check->execute();

            if (!$mem_check->fetch()) {
                // 멤버 추가
                $add2 = $db->prepare(
                    "INSERT INTO chat_room_members (room_id, user_id, last_read_at, joined_at)
                     VALUES (:rid, :uid, NOW(), NOW())"
                );
                $add2->bindParam(':rid', $room_id);
                $add2->bindParam(':uid', $approved_uid);
                $add2->execute();

                // 시스템 메시지: 초대 안내
                $sys3 = $approved_nickname . '님이 초대되었습니다.';
                $msg3 = $db->prepare(
                    "INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
                     VALUES (:rid, NULL, 'system', :content, NOW())"
                );
                $msg3->bindParam(':rid', $room_id);
                $msg3->bindParam(':content', $sys3);
                $msg3->execute();

                // chat_rooms 마지막 메시지 업데이트
                $upd2 = $db->prepare(
                    "UPDATE chat_rooms SET last_message = :lm, last_msg_time = NOW() WHERE room_id = :rid"
                );
                $upd2->bindParam(':lm',  $sys3);
                $upd2->bindParam(':rid', $room_id);
                $upd2->execute();
            }
            // 이미 멤버면 아무것도 하지 않음
        }
    

    }



    
    // 트랜잭션 커밋
    $db->commit();
    
    $message = ($action === 'approve') ? '참여 신청을 승인했습니다' : '참여 신청을 거절했습니다';
    
    $response_data = [
        'participant_id' => (int)$participant_id,
        'participation_status' => $new_status
    ];
    
    if ($action === 'approve') {
        $response_data['meeting_current_participants'] = (int)$meeting_current_participants;
    }
    
    sendSuccess($message, $response_data);
    
} catch(PDOException $e) {
    // 트랜잭션 롤백
    $db->rollBack();
    sendError('승인/거절 처리 실패: ' . $e->getMessage(), 500);
}
?>