<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/socket_emit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    sendError('잘못된 요청 데이터');
}

$room_type  = isset($data['room_type'])  ? $data['room_type']        : '';
$owner_id   = isset($data['owner_id'])   ? intval($data['owner_id']) : 0;
$member_ids = isset($data['member_ids']) ? $data['member_ids']       : [];
$meeting_id = isset($data['meeting_id']) ? intval($data['meeting_id']) : null;
$room_name  = isset($data['room_name'])  ? trim($data['room_name'])  : '';

if (!in_array($room_type, ['direct', 'group'])) {
    sendError('room_type은 direct 또는 group이어야 합니다');
}
if ($owner_id <= 0) {
    sendError('owner_id가 필요합니다');
}
if (empty($member_ids)) {
    sendError('member_ids가 필요합니다');
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 1:1 채팅방 중복 확인
    if ($room_type === 'direct') {
        $target_id = intval($member_ids[0]);
        $stmt = $db->prepare("
            SELECT cr.room_id
            FROM chat_rooms cr
            INNER JOIN chat_room_members crm1 ON cr.room_id = crm1.room_id AND crm1.user_id = ?
            INNER JOIN chat_room_members crm2 ON cr.room_id = crm2.room_id AND crm2.user_id = ?
            WHERE cr.room_type = 'direct'
            LIMIT 1
        ");
        $stmt->execute([$owner_id, $target_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // 이미 존재하는 방 반환
            sendSuccess('이미 존재하는 채팅방입니다', [
                'room_id'        => intval($existing['room_id']),
                'room_type'      => 'direct',
                'already_exists' => true
            ]);
        }
    }

    $db->beginTransaction();

    // 채팅방 생성
    $stmt = $db->prepare("
        INSERT INTO chat_rooms (room_type, room_name, meeting_id, owner_id, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$room_type, $room_name, $meeting_id ?: null, $owner_id]);
    $room_id = (int)$db->lastInsertId();

    // 멤버 추가 (owner + member_ids)
    $all_members = array_unique(array_merge([$owner_id], array_map('intval', $member_ids)));
    $stmt2 = $db->prepare("
        INSERT INTO chat_room_members (room_id, user_id, last_read_at, joined_at)
        VALUES (?, ?, NOW(), NOW())
    ");
    foreach ($all_members as $uid) {
        $stmt2->execute([$room_id, $uid]);
    }

    $message_id = null;
    $created_at = null;

    // 그룹방 시스템 메시지
    if ($room_type === 'group') {
        $stmt3 = $db->prepare("
            INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
            VALUES (?, NULL, 'system', '채팅방이 생성되었습니다.', NOW())
        ");
        $stmt3->execute([$room_id]);
        $message_id = (int)$db->lastInsertId();

        // created_at 조회
        $stmt_time = $db->prepare("
            SELECT created_at FROM chat_messages WHERE message_id = ?
        ");
        $stmt_time->execute([$message_id]);
        $time_row = $stmt_time->fetch(PDO::FETCH_ASSOC);
        $created_at = $time_row ? $time_row['created_at'] : date('Y-m-d H:i:s');

        // last_message 업데이트
        $stmt4 = $db->prepare("
            UPDATE chat_rooms
            SET last_message = '채팅방이 생성되었습니다.', last_msg_time = NOW()
            WHERE room_id = ?
        ");
        $stmt4->execute([$room_id]);
    }

    $db->commit();

    // ────────────────────────────────────────────────────────────
    // 트랜잭션 커밋 후 WebSocket emit
    // ────────────────────────────────────────────────────────────
    
    // owner를 제외한 멤버들에게 room_joined 알림
    foreach ($all_members as $uid) {
        if ($uid !== $owner_id) {
            emit_to_user($uid, 'room_joined', [
                'room_id'   => $room_id,
                'room_type' => $room_type,
                'room_name' => $room_name
            ]);
        }
    }
    
    // 그룹방이고 시스템 메시지가 있으면 message 이벤트로 broadcast
    if ($room_type === 'group' && $message_id) {
        emit_to_room($room_id, 'message', [
            'room_id'    => $room_id,
            'message_id' => $message_id,
            'msg_type'   => 'system',
            'sender_id'  => null,
            'content'    => '채팅방이 생성되었습니다.',
            'created_at' => $created_at
        ]);
    }

    sendSuccess('채팅방 생성 성공', [
        'room_id'        => $room_id,
        'room_type'      => $room_type,
        'already_exists' => false
    ]);

} catch (PDOException $e) {
    $db->rollBack();
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>