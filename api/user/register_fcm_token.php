<?php
/**
 * FCM 디바이스 토큰 등록/갱신
 *
 * POST { "fcm_token": "<FCM 디바이스 토큰 문자열>" }
 *
 * 로그인 성공 시(LoginActivity.handleLoginSuccess), 그리고 FCM 토큰이
 * 갱신될 때(FirebaseInstanceIdService.onNewToken)마다 호출되어
 * users.fcm_token, fcm_token_updated_at 을 최신 값으로 갱신한다.
 *
 * 응답:
 *   성공 { "success": true,  "message": "FCM 토큰이 등록되었습니다" }
 *   실패 { "success": false, "message": "..." }
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

// 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
$userId = get_user_id_from_token();

$requestBody = json_decode(file_get_contents('php://input'), true);

$fcmToken = isset($requestBody['fcm_token']) ? trim($requestBody['fcm_token']) : '';

if ($fcmToken === '') {
    sendError('fcm_token이 필요합니다');
}

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // 같은 디바이스 토큰이 다른 계정에 남아있으면 정리
    // (기기 하나를 여러 계정이 돌려쓴 경우 이전 계정에 푸시가 잘못 가는 것 방지)
    $clearOtherUserTokenStatement = $db->prepare(
        'UPDATE users SET fcm_token = NULL, fcm_token_updated_at = NOW() WHERE fcm_token = ? AND user_id <> ?'
    );
    $clearOtherUserTokenStatement->execute([$fcmToken, $userId]);

    // 현재 사용자에게 토큰 등록
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