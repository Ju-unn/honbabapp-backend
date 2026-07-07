<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/socket_emit.php';
require_once '../utils/push_emit.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 본인 확인 (body의 inviter_id를 신뢰하지 않음 — 위조 방지)
$inviter_id = get_user_id_from_token();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    sendError('잘못된 요청 데이터');
}

$room_id        = isset($data['room_id'])        ? intval($data['room_id'])        : 0;
$target_user_id = isset($data['target_user_id']) ? intval($data['target_user_id']) : 0;

if ($room_id <= 0)        sendError('room_id가 필요합니다');
if ($target_user_id <= 0) sendError('target_user_id가 필요합니다');

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 그룹 채팅방인지 확인 + room_name 가져오기
    $stmt = $db->prepare("
        SELECT room_type, room_name FROM chat_rooms WHERE room_id = ?
    ");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        sendError('존재하지 않는 채팅방입니다');
    }
    if ($room['room_type'] !== 'group') {
        sendError('그룹 채팅방에서만 초대할 수 있습니다');
    }
    
    $room_name = $room['room_name'];

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
    $message_id = (int)$db->lastInsertId();

    // last_message 업데이트
    $stmt = $db->prepare("
        UPDATE chat_rooms
        SET last_message = ?, last_msg_time = NOW()
        WHERE room_id = ?
    ");
    $stmt->execute([$system_msg, $room_id]);

    // created_at 조회 (message 이벤트용)
    $stmt = $db->prepare("
        SELECT created_at FROM chat_messages WHERE message_id = ?
    ");
    $stmt->execute([$message_id]);
    $msg_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $created_at = $msg_row ? $msg_row['created_at'] : date('Y-m-d H:i:s');

    $db->commit();

    // ────────────────────────────────────────────────────────────
    // 푸시 알림: 초대된 사용자에게 발송 (fire-and-forget)
    // ────────────────────────────────────────────────────────────
    push_to_user($target_user_id, 'chat_invite', '채팅방 초대', $room_name . ' 방에 초대되었어요', [
        'room_id' => $room_id,
    ]);

    // ────────────────────────────────────────────────────────────
    // 트랜잭션 커밋 후 WebSocket emit
    // ────────────────────────────────────────────────────────────
    
    // 1) 초대된 사람에게 room_joined
    emit_to_user($target_user_id, 'room_joined', [
        'room_id'   => $room_id,
        'room_type' => 'group',
        'room_name' => $room_name
    ]);
    
    // 2) 방의 기존 멤버들에게 member_joined
    emit_to_room($room_id, 'member_joined', [
        'room_id'  => $room_id,
        'user_id'  => $target_user_id,
        'nickname' => $target_user['nickname']
    ]);
    
    // 3) 시스템 메시지를 message 이벤트로 broadcast
    emit_to_room($room_id, 'message', [
        'room_id'    => $room_id,
        'message_id' => $message_id,
        'msg_type'   => 'system',
        'sender_id'  => null,
        'content'    => $system_msg,
        'created_at' => $created_at
    ]);

    sendSuccess('초대 성공', [
        'room_id'        => $room_id,
        'target_user_id' => $target_user_id,
        'nickname'       => $target_user['nickname']
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>