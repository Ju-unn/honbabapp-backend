<?php
/**
 * 푸시 알림 on/off 설정 변경
 *
 * POST { "push_enabled": 0 }   (1: ON, 0: OFF)
 *
 * ProfileNotificationSettingActivity 의 알림 스위치를 토글할 때 호출된다.
 * push_enabled = 0 인 사용자에게는 sendPushToUsers() 발송 단계에서
 * 푸시가 발송되지 않는다 (websocket/server.js 의 WHERE 조건 참고).
 *
 * 응답:
 *   성공 { "success": true, "message": "...", "data": { "push_enabled": 0 } }
 *   실패 { "success": false, "message": "..." }
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

// 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
$userId = get_user_id_from_token();

$requestBody = json_decode(file_get_contents('php://input'), true);

$pushEnabledRaw = $requestBody['push_enabled'] ?? null;
$pushEnabled = null;
if ($pushEnabledRaw === 0 || $pushEnabledRaw === 1
    || $pushEnabledRaw === '0' || $pushEnabledRaw === '1'
    || $pushEnabledRaw === true || $pushEnabledRaw === false) {
    $pushEnabled = intval($pushEnabledRaw) === 1 ? 1 : 0;
}

if ($pushEnabled === null) {
    sendError('push_enabled가 필요합니다');
}

$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    sendError('데이터베이스 연결에 실패했습니다');
}

try {
    $updateStatement = $pdo->prepare('UPDATE users SET push_enabled = ? WHERE user_id = ?');
    $updateStatement->execute([$pushEnabled, $userId]);

    sendSuccess('알림 설정이 변경되었습니다', ['push_enabled' => $pushEnabled]);
} catch (PDOException $exception) {
    error_log('[update_push_setting] DB 오류: ' . $exception->getMessage());
    sendError('알림 설정 변경에 실패했습니다');
}
?>