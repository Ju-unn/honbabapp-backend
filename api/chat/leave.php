<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    sendError('잘못된 요청 데이터');
}

$room_id = isset($data['room_id']) ? intval($data['room_id']) : 0;
$user_id = isset($data['user_id']) ? intval($data['user_id']) : 0;

if ($room_id <= 0) sendError('room_id가 필요합니다');
if ($user_id <= 0) sendError('user_id가 필요합니다');

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 멤버인지 확인 + 닉네임 조회
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

    $db->beginTransaction();

    // 멤버 삭제
    $stmt = $db->prepare("
        DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$room_id, $user_id]);

    // 남은 멤버 수 확인
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt FROM chat_room_members WHERE room_id = ?
    ");
    $stmt->execute([$room_id]);
    $remaining = intval($stmt->fetch(PDO::FETCH_ASSOC)['cnt']);

    if ($remaining === 0) {
        // 멤버가 0명이면 채팅방 삭제
        $stmt = $db->prepare("DELETE FROM chat_messages WHERE room_id = ?");
        $stmt->execute([$room_id]);

        $stmt = $db->prepare("DELETE FROM chat_rooms WHERE room_id = ?");
        $stmt->execute([$room_id]);
    } else {
        // 시스템 메시지 INSERT
        $system_msg = $member['nickname'] . '님이 나갔습니다.';
        $stmt = $db->prepare("
            INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
            VALUES (?, NULL, 'system', ?, NOW())
        ");
        $stmt->execute([$room_id, $system_msg]);

        // last_message 업데이트
        $stmt = $db->prepare("
            UPDATE chat_rooms
            SET last_message = ?, last_msg_time = NOW()
            WHERE room_id = ?
        ");
        $stmt->execute([$system_msg, $room_id]);
    }

    $db->commit();

    sendSuccess('채팅방 나가기 성공', [
        'room_id'   => $room_id,
        'remaining' => $remaining
    ]);

} catch (PDOException $e) {
    $db->rollBack();
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>