<?php

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
// 이전엔 body의 user_id를 그대로 믿어서 아무나 남의 계정을 로그아웃(FCM 토큰 초기화)시킬 수 있었음
$userId = get_user_id_from_token();

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
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