<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

$room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$page    = isset($_GET['page'])    ? intval($_GET['page'])    : 1;
$limit   = isset($_GET['limit'])   ? intval($_GET['limit'])   : 30;

if ($room_id <= 0) {
    sendError('room_id가 필요합니다');
}
if ($user_id <= 0) {
    sendError('user_id가 필요합니다');
}
if ($page < 1) $page = 1;
if ($limit < 1 || $limit > 100) $limit = 30;

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

    // 전체 메시지 수
    $stmt = $db->prepare("
        SELECT COUNT(*) as total FROM chat_messages WHERE room_id = ?
    ");
    $stmt->execute([$room_id]);
    $total_count = intval($stmt->fetch(PDO::FETCH_ASSOC)['total']);
    $total_pages = ceil($total_count / $limit);

    // 방 전체 멤버 수 (읽음 카운트 계산용)
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt FROM chat_room_members WHERE room_id = ?
    ");
    $stmt->execute([$room_id]);
    $member_count = intval($stmt->fetch(PDO::FETCH_ASSOC)['cnt']);

    // 메시지 목록 조회 (오래된 순으로 반환)
    $offset = ($page - 1) * $limit;
    $stmt = $db->prepare("
        SELECT
            cm.message_id,
            cm.room_id,
            cm.sender_id,
            u.user_nickname AS sender_name,
            u.user_profile_image AS sender_profile_image,
            cm.msg_type,
            cm.content,
            cm.image_url,
            cm.image_urls,
            cm.created_at,
            (
                SELECT COUNT(*)
                FROM chat_message_reads cmr
                WHERE cmr.message_id = cm.message_id
            ) as read_count
        FROM chat_messages cm
        LEFT JOIN users u ON cm.sender_id = u.user_id
        WHERE cm.room_id = ?
        ORDER BY cm.message_id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$room_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 타입 변환 + unread_count 계산
    foreach ($messages as &$msg) {
        $msg['message_id'] = intval($msg['message_id']);
        $msg['room_id']    = intval($msg['room_id']);
        $msg['sender_id']  = $msg['sender_id'] ? intval($msg['sender_id']) : null;
        // 안읽은 수 = 전체 멤버 수 - 읽은 수
        $msg['unread_count'] = ($member_count - 1) - intval($msg['read_count']);
        if ($msg['unread_count'] < 0) $msg['unread_count'] = 0;
        unset($msg['read_count']); // read_count는 내부 계산용이므로 제거

        // image_urls 정규화: NULL이면 image_url 단일값으로 폴백 
        if (!empty($msg['image_urls'])) {
            $msg['image_urls'] = json_decode($msg['image_urls'], true);
        } elseif (!empty($msg['image_url'])) {
            $msg['image_urls'] = [$msg['image_url']];
        } else {
            $msg['image_urls'] = [];
        }
    }

    // 오래된 순으로 뒤집기 (화면에서 위→아래 시간순)
    $messages = array_reverse($messages);

    sendSuccess('메시지 조회 성공', [
        'messages'    => $messages,
        'total_count' => $total_count,
        'page'        => $page,
        'total_pages' => $total_pages
    ]);

} catch (PDOException $e) {
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>