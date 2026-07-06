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

if (empty($input['user_location'])) {
    sendError('위치 정보가 필요합니다.');
}

$user_location = trim($input['user_location']);

$database = new Database();
$db = $database->getConnection();
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다.', 500);
}

try {
    $stmt = $db->prepare('UPDATE users SET user_location = :user_location WHERE user_id = :user_id');
    $stmt->bindValue(':user_location', $user_location);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    sendSuccess('위치가 변경되었습니다.');
} catch (PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>