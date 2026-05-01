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

if ($room_id <= 0) {
    sendError('room_id가 필요합니다');
}
if ($user_id <= 0) {
    sendError('user_id가 필요합니다');
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 내가 이 방의 멤버인지 확인
    $stmt = $db->prepare("
        SELECT id FROM chat_room_members
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$room_id, $user_id]);
    if (!$stmt->fetch()) {
        sendError('채팅방 멤버가 아닙니다', 403);
    }

    // 이 방의 모든 메시지 ID 조회 (내가 보낸 것 제외)
    $stmt = $db->prepare("
        SELECT message_id FROM chat_messages
        WHERE room_id = ? AND sender_id != ?
    ");
    $stmt->execute([$room_id, $user_id]);
    $message_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $marked_count = 0;

    if (!empty($message_ids)) {
        // chat_message_reads에 INSERT IGNORE (이미 읽은 건 건너뜀)
        $stmt2 = $db->prepare("
            INSERT IGNORE INTO chat_message_reads (message_id, user_id, read_at)
            VALUES (?, ?, NOW())
        ");
        foreach ($message_ids as $message_id) {
            $stmt2->execute([$message_id, $user_id]);
            $marked_count += $stmt2->rowCount();
        }
    }

    // last_read_at 업데이트
    $stmt3 = $db->prepare("
        UPDATE chat_room_members
        SET last_read_at = NOW()
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt3->execute([$room_id, $user_id]);

    sendSuccess('읽음 처리 완료', [
        'marked_count' => $marked_count
    ]);

} catch (PDOException $e) {
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>