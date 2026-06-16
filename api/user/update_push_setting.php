<?php
/**
 * 푸시 알림 on/off 설정 변경
 *
 * POST { "user_id": 7, "push_enabled": 0 }   (1: ON, 0: OFF)
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

$requestBody = json_decode(file_get_contents('php://input'), true);

$userId = isset($requestBody['user_id']) ? intval($requestBody['user_id']) : 0;

$pushEnabledRaw = $requestBody['push_enabled'] ?? null;
$pushEnabled = null;
if ($pushEnabledRaw === 0 || $pushEnabledRaw === 1
    || $pushEnabledRaw === '0' || $pushEnabledRaw === '1'
    || $pushEnabledRaw === true || $pushEnabledRaw === false) {
    $pushEnabled = intval($pushEnabledRaw) === 1 ? 1 : 0;
}

if ($userId <= 0 || $pushEnabled === null) {
    sendError('user_id, push_enabled=가 필요합니다');
}

$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    sendError('데이터베이스 연결에 실패했습니다');
}

try {
    $checkStatement = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ?');
    $checkStatement->execute([$userId]);

    if ($checkStatement->fetch() === false) {
        sendError('사용자를 찾을 수 없습니다');
    }

    $updateStatement = $pdo->prepare('UPDATE users SET push_enabled = ? WHERE user_id = ?');
    $updateStatement->execute([$pushEnabled, $userId]);

    sendSuccess('알림 설정이 변경되었습니다', ['push_enabled' => $pushEnabled]);
} catch (PDOException $exception) {
    error_log('[update_push_setting] DB 오류: ' . $exception->getMessage());
    sendError('알림 설정 변경에 실패했습니다');
}
?>