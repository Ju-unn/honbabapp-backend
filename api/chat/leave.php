<?php
/**
 * 채팅방 나가기 API (V2 - 위임/패널티 포함)
 *
 * POST /api/chat/leave.php
 * Request:  { "room_id": 1, "user_id": 123 }
 *
 * Response (성공 - group):
 * {
 *   "success": true,
 *   "message": "채팅방 나가기 성공",
 *   "data": {
 *     "room_id": 1,
 *     "remaining": 3,
 *     "is_host": true,
 *     "is_host_transferred": true,
 *     "new_host_id": 456,
 *     "room_deleted": false,
 *     "meeting_deleted": false,
 *     "penalty_applied": 0.3,
 *     "new_manner_score": 36.2
 *   }
 * }
 *
 * 정책:
 *  - 호스트 나가기 : 다른 '승인' 참여자 있으면 자동 위임
 *                   (user_manner_score DESC, approved_at ASC 1위)
 *                   없으면 모임/채팅방 삭제
 *  - 참여자 나가기 : meeting_participants '취소', current -=1, 모집완료→모집중
 *  - 패널티      : D-3 이전 0, D-2 -0.3, D-1 이내 -1.0
 *  - 매너점수 하한 : GREATEST(0, score - penalty)
 *  - direct 방   : 위임/패널티 없음, 기존 로직 유지
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/socket_emit.php'; 
 require_once '../utils/jwt.php';
 
 // ───────────────────────────────────────────
 // 1) 요청 검증
 // ───────────────────────────────────────────
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     sendError('POST 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
 $user_id = get_user_id_from_token();
 
 $data = json_decode(file_get_contents('php://input'), true);
 if (!$data) {
     sendError('잘못된 요청 데이터');
 }
 
 $room_id = isset($data['room_id']) ? intval($data['room_id']) : 0;
 
 if ($room_id <= 0) sendError('room_id가 필요합니다');

// ───────────────────────────────────────────
// 2) DB 연결
// ───────────────────────────────────────────
$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 멤버 + 닉네임 확인
    $stmt = $db->prepare("
        SELECT u.user_nickname AS nickname
        FROM chat_room_members crm
        INNER JOIN users u ON crm.user_id = u.user_id
        WHERE crm.room_id = ? AND crm.user_id = ?
    ");
    $stmt->execute([$room_id, $user_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        sendError('채팅방 멤버가 아닙니다');
    }
    $nickname = $member['nickname'];

    // ───────────────────────────────────────
    // 3) 트랜잭션 시작
    // ───────────────────────────────────────
    $db->beginTransaction();

    // [LOCK] chat_rooms 조회 (FOR UPDATE) — 동시 접근 차단
    $stmt = $db->prepare("
        SELECT room_id, room_type, owner_id, meeting_id
        FROM chat_rooms
        WHERE room_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        $db->rollBack();
        sendError('존재하지 않는 채팅방입니다');
    }

    // ═══════════════════════════════════════════════════════
    // ROUTE A : direct 방 — 위임/패널티/모임처리 없음
    // ═══════════════════════════════════════════════════════
    if ($room['room_type'] === 'direct') {
        $result = handle_direct_leave($db, $room_id, $user_id, $nickname);
        $db->commit();

        // direct는 broadcast 안 함 (1:1이라 상대방 한 명에게 알릴 필요는 있지만,
        // 메시지는 이미 INSERT 되었으므로 클라가 새 메시지 받으면 충분)
        sendSuccess('채팅방 나가기 성공', $result);
    }

    // ═══════════════════════════════════════════════════════
    // ROUTE B : group 방 — 풀 로직
    // ═══════════════════════════════════════════════════════

    $meeting_id = $room['meeting_id'];
    if (!$meeting_id) {
        $db->rollBack();
        sendError('그룹 방인데 meeting_id가 없습니다', 500);
    }

    // [LOCK] meetings 조회 (FOR UPDATE)
    $stmt = $db->prepare("
        SELECT meeting_id, meeting_host_id, meeting_date, meeting_status,
               meeting_current_participants, meeting_max_participants
        FROM meetings
        WHERE meeting_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$meeting) {
        $db->rollBack();
        sendError('존재하지 않는 모임입니다', 404);
    }

    $is_host = (intval($meeting['meeting_host_id']) === $user_id);

    // ───────────────────────────────────────
    // 4) 패널티 계산 (D-day 기반)
    // ───────────────────────────────────────
    $penalty = calculate_penalty($meeting['meeting_date']);

    // 응답에 쓸 변수들 (기본값)
    $is_host_transferred = false;
    $new_host_id         = null;
    $room_deleted        = false;
    $meeting_deleted     = false;
    $new_host_nickname   = null;

    // ───────────────────────────────────────
    // 5) 호스트 / 참여자 분기
    // ───────────────────────────────────────
    if ($is_host) {
        // ─── 5-A) 호스트 나가기 ───────────────

        // 위임 후보 SELECT (매너점수 DESC, 승인시각 ASC)
        $stmt = $db->prepare("
            SELECT mp.user_id, u.user_nickname
            FROM meeting_participants mp
            JOIN users u ON mp.user_id = u.user_id
            WHERE mp.meeting_id = ?
              AND mp.participation_status = '승인'
              AND mp.user_id != ?
            ORDER BY u.user_manner_score DESC, mp.approved_at ASC
            LIMIT 1
        ");
        $stmt->execute([$meeting_id, $user_id]);
        $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($candidate) {
            // ── 5-A-1) 위임 가능 ───────
            $new_host_id       = intval($candidate['user_id']);
            $new_host_nickname = $candidate['user_nickname'];

            // meetings.meeting_host_id 변경
            $stmt = $db->prepare("UPDATE meetings SET meeting_host_id = ? WHERE meeting_id = ?");
            $stmt->execute([$new_host_id, $meeting_id]);

            // chat_rooms.owner_id 변경
            $stmt = $db->prepare("UPDATE chat_rooms SET owner_id = ? WHERE room_id = ?");
            $stmt->execute([$new_host_id, $room_id]);

            // 본인의 meeting_participants 도 '취소' 처리 (호스트도 participants에 row 있음)
            $stmt = $db->prepare("
                UPDATE meeting_participants
                SET participation_status = '취소'
                WHERE meeting_id = ? AND user_id = ?
            ");
            $stmt->execute([$meeting_id, $user_id]);

            $is_host_transferred = true;

        } else {
            // ── 5-A-2) 위임 불가 → 모임/채팅방 통째 삭제 ───────

            // 호스트가 혼자 있어 모임이 삭제되는 경우 매너점수 차감 없음 (날짜 무관)
            $penalty = 0.0;

            // 모임 상태 '취소' + current_participants 0 으로 초기화
            // (취소된 모임은 화면에서 안 보이지만, 데이터 정합성을 위해 0 으로 맞춤)
            $stmt = $db->prepare("
                UPDATE meetings
                SET meeting_status = '취소',
                    meeting_current_participants = 0
                WHERE meeting_id = ?
            ");
            $stmt->execute([$meeting_id]);

            // 채팅방 관련 데이터 삭제 (FK 순서 중요)
            // chat_message_reads → chat_messages → chat_room_members → chat_rooms
            $stmt = $db->prepare("
                DELETE cmr FROM chat_message_reads cmr
                INNER JOIN chat_messages cm ON cmr.message_id = cm.message_id
                WHERE cm.room_id = ?
            ");
            $stmt->execute([$room_id]);

            $stmt = $db->prepare("DELETE FROM chat_messages WHERE room_id = ?");
            $stmt->execute([$room_id]);

            $stmt = $db->prepare("DELETE FROM chat_room_members WHERE room_id = ?");
            $stmt->execute([$room_id]);

            $stmt = $db->prepare("DELETE FROM chat_rooms WHERE room_id = ?");
            $stmt->execute([$room_id]);

            $room_deleted    = true;
            $meeting_deleted = true;
        }

    } else {
        // ─── 5-B) 참여자 나가기 ───────────────

        $stmt = $db->prepare("
            UPDATE meeting_participants
            SET participation_status = '취소'
            WHERE meeting_id = ? AND user_id = ?
        ");
        $stmt->execute([$meeting_id, $user_id]);
    }

    // ───────────────────────────────────────
    // 6) 방이 살아있는 경우의 후속 작업
    //    - meeting_current_participants -= 1
    //    - 모집완료 → 모집중 복귀
    //    - chat_room_members 삭제
    //    - 시스템 메시지 INSERT
    // ───────────────────────────────────────
    if (!$room_deleted) {
        // 6-1) meeting_current_participants -= 1
        $new_current = max(0, intval($meeting['meeting_current_participants']) - 1);
        $stmt = $db->prepare("
            UPDATE meetings
            SET meeting_current_participants = ?
            WHERE meeting_id = ?
        ");
        $stmt->execute([$new_current, $meeting_id]);

        // 6-2) '모집완료' → '모집중'
        if ($meeting['meeting_status'] === '모집완료' && $new_current < intval($meeting['meeting_max_participants'])) {
            $stmt = $db->prepare("UPDATE meetings SET meeting_status = '모집중' WHERE meeting_id = ?");
            $stmt->execute([$meeting_id]);
        }

        // 6-3) chat_room_members 삭제
        $stmt = $db->prepare("DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute([$room_id, $user_id]);

        // 6-4) 시스템 메시지 INSERT
        //   - 호스트 위임 발생 → 두 줄 ("○○님이 새 방장이 되었습니다." + "○○님이 나갔습니다.")
        //   - 일반 참여자/위임 없는 호스트 → 한 줄 ("○○님이 나갔습니다.")
        $messages_to_insert = [];
        if ($is_host_transferred) {
            $messages_to_insert[] = $new_host_nickname . '님이 새 방장이 되었습니다.';
        }
        $messages_to_insert[] = $nickname . '님이 나갔습니다.';

        $insert_stmt = $db->prepare("
            INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
            VALUES (?, NULL, 'system', ?, NOW())
        ");
        foreach ($messages_to_insert as $msg_content) {
            $insert_stmt->execute([$room_id, $msg_content]);
        }

        // 6-5) chat_rooms.last_message 업데이트 (마지막 메시지로)
        $last_msg = end($messages_to_insert);
        $stmt = $db->prepare("
            UPDATE chat_rooms
            SET last_message = ?, last_msg_time = NOW()
            WHERE room_id = ?
        ");
        $stmt->execute([$last_msg, $room_id]);
    }

    // ───────────────────────────────────────
    // 7) 매너점수 차감 (GREATEST 로 0 하한 보호)
    // ───────────────────────────────────────
    if ($penalty > 0) {
        $stmt = $db->prepare("
            UPDATE users
            SET user_manner_score = GREATEST(0, user_manner_score - ?)
            WHERE user_id = ?
        ");
        $stmt->execute([$penalty, $user_id]);
    }

    // 차감 후 점수 재조회 (응답에 포함)
    $stmt = $db->prepare("SELECT user_manner_score FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $new_manner_score = (float)$stmt->fetchColumn();

    // ───────────────────────────────────────
    // 8) 남은 멤버 수 조회 (응답용)
    // ───────────────────────────────────────
    if ($room_deleted) {
        $remaining = 0;
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM chat_room_members WHERE room_id = ?");
        $stmt->execute([$room_id]);
        $remaining = intval($stmt->fetchColumn());
    }

    // ───────────────────────────────────────
    // 9) COMMIT
    // ───────────────────────────────────────
    $db->commit();

    // ───────────────────────────────────────
    // 10) WebSocket broadcast (트랜잭션 밖에서)
    //     - emit_to_room 은 socket_emit.php 의 헬퍼
    //     - 실패해도 사용자 응답에는 영향 X (best-effort)
    // ───────────────────────────────────────
    if (!$room_deleted) {
        // 멤버 빠짐 알림
        emit_to_room($room_id, 'member_left', [
            'room_id'         => $room_id,
            'user_id'         => $user_id,
            'nickname'        => $nickname,
            'remaining'       => $remaining,
        ]);

        // 호스트 위임 알림
        if ($is_host_transferred) {
            emit_to_room($room_id, 'host_changed', [
                'room_id'      => $room_id,
                'new_host_id'  => $new_host_id,
                'new_nickname' => $new_host_nickname,
            ]);
        }
    }

    // ───────────────────────────────────────
    // 11) 응답 반환
    // ───────────────────────────────────────
    sendSuccess('채팅방 나가기 성공', [
        'room_id'             => $room_id,
        'remaining'           => $remaining,
        'is_host'             => $is_host,
        'is_host_transferred' => $is_host_transferred,
        'new_host_id'         => $new_host_id,
        'room_deleted'        => $room_deleted,
        'meeting_deleted'     => $meeting_deleted,
        'penalty_applied'     => (float)$penalty,
        'new_manner_score'    => $new_manner_score,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('DB 오류: ' . $e->getMessage(), 500);
}


// ═══════════════════════════════════════════════════════
// 헬퍼 함수들
// ═══════════════════════════════════════════════════════

/**
 *
 * @param string $meeting_date 'YYYY-MM-DD HH:MM:SS'
 * @return float
 */
function calculate_penalty($meeting_date) {
    $diff_seconds = strtotime($meeting_date) - time();
 
    // Issue #7: 이미 종료된 모임 → 패널티 없음
    if ($diff_seconds <= 0) return 0.0;
 
    $diff_hours = $diff_seconds / 3600;
 
    if ($diff_hours < 24) return 1.0;  // D-1 이내
    if ($diff_hours < 48) return 0.3;  // D-2
    return 0.0;                         // D-3 이전
}


/**
 * 1:1 (direct) 방 나가기 처리
 *   - 위임/패널티/모임 처리 없음
 *   - 멤버 삭제 + 남은 멤버 0 이면 방 전체 삭제, 아니면 시스템 메시지
 */
function handle_direct_leave($db, $room_id, $user_id, $nickname) {
    // 멤버 삭제
    $stmt = $db->prepare("DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);

    // 남은 멤버 수
    $stmt = $db->prepare("SELECT COUNT(*) FROM chat_room_members WHERE room_id = ?");
    $stmt->execute([$room_id]);
    $remaining = intval($stmt->fetchColumn());

    $room_deleted = false;

    if ($remaining === 0) {
        // 방 전체 삭제
        $stmt = $db->prepare("
            DELETE cmr FROM chat_message_reads cmr
            INNER JOIN chat_messages cm ON cmr.message_id = cm.message_id
            WHERE cm.room_id = ?
        ");
        $stmt->execute([$room_id]);

        $stmt = $db->prepare("DELETE FROM chat_messages WHERE room_id = ?");
        $stmt->execute([$room_id]);

        $stmt = $db->prepare("DELETE FROM chat_rooms WHERE room_id = ?");
        $stmt->execute([$room_id]);

        $room_deleted = true;
    } else {
        // 시스템 메시지
        $msg = $nickname . '님이 나갔습니다.';
        $stmt = $db->prepare("
            INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
            VALUES (?, NULL, 'system', ?, NOW())
        ");
        $stmt->execute([$room_id, $msg]);

        $stmt = $db->prepare("
            UPDATE chat_rooms
            SET last_message = ?, last_msg_time = NOW()
            WHERE room_id = ?
        ");
        $stmt->execute([$msg, $room_id]);
    }

    // 매너점수는 그대로 조회만 (direct 는 차감 없음)
    $stmt = $db->prepare("SELECT user_manner_score FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $score = (float)$stmt->fetchColumn();

    return [
        'room_id'             => $room_id,
        'remaining'           => $remaining,
        'is_host'             => false,
        'is_host_transferred' => false,
        'new_host_id'         => null,
        'room_deleted'        => $room_deleted,
        'meeting_deleted'     => false,
        'penalty_applied'     => 0.0,
        'new_manner_score'    => $score,
    ];
}
?>