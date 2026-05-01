<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($user_id <= 0) {
    sendError('user_id가 필요합니다');
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 내가 속한 채팅방 목록 + 안읽은 수 계산
    $stmt = $db->prepare("
        SELECT
            cr.room_id,
            cr.room_type,
            cr.room_name,
            cr.meeting_id,
            cr.owner_id,
            cr.last_message,
            cr.last_msg_time,
            (
                SELECT COUNT(*)
                FROM chat_messages cm
                WHERE cm.room_id = cr.room_id
                AND (cm.sender_id != :uid1 OR cm.sender_id IS NULL)
                AND cm.created_at > COALESCE(crm.last_read_at, '1970-01-01')
            ) as unread_count
        FROM chat_rooms cr
        INNER JOIN chat_room_members crm
            ON cr.room_id = crm.room_id
            AND crm.user_id = :uid2
        ORDER BY cr.last_msg_time DESC
    ");
    $stmt->execute([':uid1' => $user_id, ':uid2' => $user_id]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 각 방의 참여자 목록 조회
    foreach ($rooms as &$room) {
        $stmt2 = $db->prepare("
            SELECT u.user_id, u.user_nickname, u.user_profile_image, crm.last_read_at
            FROM chat_room_members crm
            INNER JOIN users u ON crm.user_id = u.user_id
            WHERE crm.room_id = ?
        ");
        $stmt2->execute([$room['room_id']]);
        $room['participants'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // 숫자 타입 변환
        $room['room_id'] = intval($room['room_id']);
        $room['owner_id'] = intval($room['owner_id']);
        $room['unread_count'] = intval($room['unread_count']);
        $room['meeting_id'] = $room['meeting_id'] ? intval($room['meeting_id']) : null;
    }

    // 변경 후
    sendSuccess('채팅방 목록 조회 성공', $rooms);

} catch (PDOException $e) {
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>