<?php
/**
 * FCM 디바이스 토큰 등록/갱신
 *
 * POST { "user_id": 7, "fcm_token": "<FCM 디바이스 토큰 문자열>" }
 *
 * 로그인 성공 시(LoginActivity.handleLoginSuccess)마다 호출되어
 * users.fcm_token, fcm_token_updated_at 을 최신 값으로 갱신한다.
 *
 * 응답:
 *   성공 { "success": true,  "message": "FCM 토큰이 등록되었습니다" }
 *   실패 { "success": false, "message": "..." }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

$requestBody = json_decode(file_get_contents('php://input'), true);

$userId   = isset($requestBody['user_id']) ? intval($requestBody['user_id']) : 0;
$fcmToken = isset($requestBody['fcm_token']) ? trim($requestBody['fcm_token']) : '';

if ($userId <= 0 || $fcmToken === '') {
    sendError('user_id, fcm_token 이 필요합니다');
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

    $updateStatement = $db->prepare(
        'UPDATE users SET fcm_token = ?, fcm_token_updated_at = NOW() WHERE user_id = ?'
    );
    $updateStatement->execute([$fcmToken, $userId]);

    sendSuccess('FCM 토큰이 등록되었습니다');
} catch (PDOException $exception) {
    error_log('[register_fcm_token] DB 오류: ' . $exception->getMessage());
    sendError('FCM 토큰 등록에 실패했습니다', 500);
}
?>