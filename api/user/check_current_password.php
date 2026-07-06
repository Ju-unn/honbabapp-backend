<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
$user_id = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['current_password'])) {
    sendError('current_password가 필요합니다');
}

$current_password = $input['current_password'];

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $query = 'SELECT user_password FROM users WHERE user_id = :user_id';
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendError('존재하지 않는 사용자 입니다.', 404);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!password_verify($current_password, $user['user_password'])) {
        sendError('현재 비밀번호가 일치하지 않습니다');
    }

    sendSuccess('현재 비밀번호가 확인되었습니다', null);

} catch (PDOException $e) {
    sendError('데이터베이스 오류 : ' . $e->getMessage(), 500);
}
?>