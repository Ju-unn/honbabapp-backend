<?php

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$requestBody = json_decode(file_get_contents('php://input'), true);

$userId = isset($requestBody['user_id']) ? intval($requestBody['user_id']) : 0;

if ($userId <= 0) {
    sendError('user_id 가 필요합니다');
}

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $checkStatement = $db->prepare('SELECT user_id FROM users WHERE user_id = ?');
    $checkStatement->execute([$userId]);

    if ($checkStatement->fetch() === false) {
        sendError('사용자를 찾을 수 없습니다', 404);
    }

    // FCM 토큰 NULL 처리: 이 기기로 더 이상 푸시 알림을 발송하지 않는다
    $clearTokenStatement = $db->prepare(
        'UPDATE users SET fcm_token = NULL, fcm_token_updated_at = NOW() WHERE user_id = ?'
    );
    $clearTokenStatement->execute([$userId]);

    sendSuccess('로그아웃 되었습니다');

} catch (PDOException $exception) {
    error_log('[logout] DB 오류: ' . $exception->getMessage());
    sendError('로그아웃 처리 중 오류가 발생했습니다', 500);
}

?>