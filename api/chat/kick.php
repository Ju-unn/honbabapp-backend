<?php
/**
 * 방장의 멤버 강퇴 API
 *
 * POST /api/chat/kick.php
 * Request:  { "room_id": 1, "host_id": 5, "target_user_id": 7 }
 *
 * Response (성공):
 * {
 *   "success": true,
 *   "message": "강퇴 완료",
 *   "data": {
 *     "room_id": 1,
 *     "target_user_id": 7,
 *     "remaining": 3,
 *     "meeting_current_participants": 3
 *   }
 * }
 *
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/socket_emit.php';

// ───────────────────────────────────────────
// 1) 요청 검증
// ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    sendError('잘못된 요청 데이터');
}

$room_id        = isset($data['room_id']) ? intval($data['room_id']) : 0;
$host_id        = isset($data['host_id']) ? intval($data['host_id']) : 0;
$target_user_id = isset($data['target_user_id']) ? intval($data['target_user_id']) : 0;

if ($room_id <= 0)        sendError('room_id가 필요합니다');
if ($host_id <= 0)        sendError('host_id가 필요합니다');
if ($target_user_id <= 0) sendError('target_user_id가 필요합니다');

// 자기 자신 강퇴 차단 (DB 락 잡기 전에 빠르게 거절)
if ($host_id === $target_user_id) {
    sendError('자기 자신은 강퇴할 수 없습니다');
}

// ───────────────────────────────────────────
// 2) DB 연결
// ───────────────────────────────────────────
$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // ───────────────────────────────────────
    // 3) 트랜잭션 시작
    // ───────────────────────────────────────
    $db->beginTransaction();

    // [LOCK] chat_rooms 조회 (FOR UPDATE) — 동시 강퇴/나가기 충돌 방지
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
        sendError('존재하지 않는 채팅방입니다', 404);
    }

    // 1:1(direct) 방 차단 — 1:1 에서는 강퇴 개념이 없음
    if ($room['room_type'] === 'direct') {
        $db->rollBack();
        sendError('1:1 방에서는 강퇴할 수 없습니다');
    }

    // 방장 권한 검증 (chat_rooms.owner_id)
    if (intval($room['owner_id']) !== $host_id) {
        $db->rollBack();
        sendError('방장만 강퇴할 수 있습니다', 403);
    }

    $meeting_id = $room['meeting_id'];
    if (!$meeting_id) {
        $db->rollBack();
        sendError('그룹 방인데 meeting_id가 없습니다', 500);
    }

    // [LOCK] meetings 조회 (FOR UPDATE)
    $stmt = $db->prepare("
        SELECT meeting_id, meeting_host_id, meeting_status,
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

    // 모임장 권한 이중 검증 (chat_rooms.owner_id 와 meetings.meeting_host_id 가
    // 어긋나는 비정상 상태를 방어)
    if (intval($meeting['meeting_host_id']) !== $host_id) {
        $db->rollBack();
        sendError('모임장만 강퇴할 수 있습니다', 403);
    }

    // ───────────────────────────────────────
    // 4) 대상자가 실제로 방에 있는지 + 닉네임 조회
    // ───────────────────────────────────────
    $stmt = $db->prepare("
        SELECT u.user_nickname AS nickname
        FROM chat_room_members crm
        INNER JOIN users u ON crm.user_id = u.user_id
        WHERE crm.room_id = ? AND crm.user_id = ?
    ");
    $stmt->execute([$room_id, $target_user_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $db->rollBack();
        sendError('대상자가 채팅방에 없습니다');
    }
    $target_nickname = $target['nickname'];

    // ───────────────────────────────────────
    // 5) meeting_participants 상태를 '강퇴' 로 변경
    //    (대상자가 '승인' 상태일 때만 변경 — 다른 상태면 비정상)
    // ───────────────────────────────────────
    $stmt = $db->prepare("
        UPDATE meeting_participants
        SET participation_status = '강퇴'
        WHERE meeting_id = ? AND user_id = ? AND participation_status = '승인'
    ");
    $stmt->execute([$meeting_id, $target_user_id]);

    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        sendError('승인 상태인 참여자만 강퇴할 수 있습니다');
    }

    // ───────────────────────────────────────
    // 6) meeting_current_participants -= 1
    // ───────────────────────────────────────
    $new_current = max(0, intval($meeting['meeting_current_participants']) - 1);
    $stmt = $db->prepare("
        UPDATE meetings
        SET meeting_current_participants = ?
        WHERE meeting_id = ?
    ");
    $stmt->execute([$new_current, $meeting_id]);

    // ───────────────────────────────────────
    // 7) 모집완료 → 모집중 복귀
    // ───────────────────────────────────────
    if ($meeting['meeting_status'] === '모집완료'
        && $new_current < intval($meeting['meeting_max_participants'])) {
        $stmt = $db->prepare("
            UPDATE meetings SET meeting_status = '모집중' WHERE meeting_id = ?
        ");
        $stmt->execute([$meeting_id]);
    }

    // ───────────────────────────────────────
    // 8) chat_room_members 에서 대상자 삭제
    // ───────────────────────────────────────
    $stmt = $db->prepare("
        DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$room_id, $target_user_id]);

    // ───────────────────────────────────────
    // 9) 시스템 메시지 INSERT
    // ───────────────────────────────────────
    $sys_msg = $target_nickname . '님이 강퇴되었습니다.';
    $stmt = $db->prepare("
        INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
        VALUES (?, NULL, 'system', ?, NOW())
    ");
    $stmt->execute([$room_id, $sys_msg]);

    // chat_rooms.last_message 업데이트
    $stmt = $db->prepare("
        UPDATE chat_rooms
        SET last_message = ?, last_msg_time = NOW()
        WHERE room_id = ?
    ");
    $stmt->execute([$sys_msg, $room_id]);

    // ───────────────────────────────────────
    // 10) 남은 멤버 수 조회 (응답용)
    // ───────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) FROM chat_room_members WHERE room_id = ?");
    $stmt->execute([$room_id]);
    $remaining = intval($stmt->fetchColumn());

    // ───────────────────────────────────────
    // 11) COMMIT
    // ───────────────────────────────────────
    $db->commit();

    // ───────────────────────────────────────
    // 12) WebSocket broadcast
    // ───────────────────────────────────────

    // 12-1) 방에 남아있는 멤버들에게 인원수 갱신 알림
    //       leave.php 의 'member_left' 와 같은 이벤트를 재사용하되,
    //       reason='kicked' 로 강퇴임을 구분할 수 있게 함
    emit_to_room($room_id, 'member_left', [
        'room_id'   => $room_id,
        'user_id'   => $target_user_id,
        'nickname'  => $target_nickname,
        'remaining' => $remaining,
        'reason'    => 'kicked',
    ]);

    // 12-2) 강퇴 당사자에게 직접 통보
    //       대상자는 이미 chat_room_members 에서 빠졌으므로
    //       emit_to_room 의 fan-out 으로는 받을 수 없음.
    //       → user_{id} 개인 채널로 직접 emit
    emit_to_user($target_user_id, 'kicked', [
        'room_id'        => $room_id,
        'target_user_id' => $target_user_id,
    ]);

    // ───────────────────────────────────────
    // 13) 응답 반환
    // ───────────────────────────────────────
    sendSuccess('강퇴 완료', [
        'room_id'                      => $room_id,
        'target_user_id'               => $target_user_id,
        'remaining'                    => $remaining,
        'meeting_current_participants' => $new_current,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>