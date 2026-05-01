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

$room_id        = isset($data['room_id'])        ? intval($data['room_id'])        : 0;
$inviter_id     = isset($data['inviter_id'])     ? intval($data['inviter_id'])     : 0;
$target_user_id = isset($data['target_user_id']) ? intval($data['target_user_id']) : 0;

if ($room_id <= 0)        sendError('room_id가 필요합니다');
if ($inviter_id <= 0)     sendError('inviter_id가 필요합니다');
if ($target_user_id <= 0) sendError('target_user_id가 필요합니다');

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 그룹 채팅방인지 확인
    $stmt = $db->prepare("
        SELECT room_type FROM chat_rooms WHERE room_id = ?
    ");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        sendError('존재하지 않는 채팅방입니다');
    }
    if ($room['room_type'] !== 'group') {
        sendError('그룹 채팅방에서만 초대할 수 있습니다');
    }

    // 초대자가 멤버인지 확인
    $stmt = $db->prepare("
        SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$room_id, $inviter_id]);
    if (!$stmt->fetch()) {
        sendError('채팅방 멤버만 초대할 수 있습니다');
    }

    // 이미 멤버인지 확인
    $stmt = $db->prepare("
        SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$room_id, $target_user_id]);
    if ($stmt->fetch()) {
        sendError('이미 채팅방 멤버입니다');
    }

    // 초대할 유저 닉네임 조회
    $stmt = $db->prepare("
        SELECT user_nickname AS nickname FROM users WHERE user_id = ?
    ");
    $stmt->execute([$target_user_id]);
    $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target_user) {
        sendError('존재하지 않는 유저입니다');
    }

    $db->beginTransaction();

    // 멤버 추가
    $stmt = $db->prepare("
        INSERT INTO chat_room_members (room_id, user_id, last_read_at, joined_at)
        VALUES (?, ?, NOW(), NOW())
    ");
    $stmt->execute([$room_id, $target_user_id]);

    // 시스템 메시지 INSERT
    $system_msg = $target_user['nickname'] . '님이 초대되었습니다.';
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

    $db->commit();

    sendSuccess('초대 성공', [
        'room_id'        => $room_id,
        'target_user_id' => $target_user_id,
        'nickname'       => $target_user['nickname']
    ]);

} catch (PDOException $e) {
    $db->rollBack();
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>