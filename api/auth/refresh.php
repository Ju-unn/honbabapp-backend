<?php
/**
 * Access Token 재발급 API
 * POST /api/auth/refresh.php
 * body: { "refresh_token": "<클라이언트가 저장해둔 refresh token>" }
 *
 * 검증 흐름:
 * 1. 전달받은 refresh token을 해시해서 DB에서 조회
 * 2. 존재하지 않으면 위조된 값 → 401
 * 3. 존재하지만 이미 폐기(revoked_at IS NOT NULL)된 토큰이 재사용됐다면
 *    탈취 의심 → 해당 유저의 모든 refresh token을 폐기하고 재로그인 요구
 * 4. 만료됐으면 401
 * 5. 정상이면 현재 토큰을 폐기(rotate)하고 새 access+refresh 토큰 쌍 발급
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['refresh_token'])) {
    sendError('refresh_token이 필요합니다', 401);
}

$received_refresh_token = trim($input['refresh_token']);
$token_hash = hash('sha256', $received_refresh_token);

$database_connection = new Database();
$database = $database_connection->getConnection();

if ($database === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $database->beginTransaction();

    $select_token_statement = $database->prepare("
        SELECT refresh_token_id, user_id, expires_at, revoked_at
        FROM refresh_tokens
        WHERE token_hash = :token_hash
        FOR UPDATE
    ");
    $select_token_statement->bindParam(':token_hash', $token_hash);
    $select_token_statement->execute();

    if ($select_token_statement->rowCount() === 0) {
        $database->rollBack();
        sendError('유효하지 않은 인증 정보입니다', 401);
    }

    $token_record = $select_token_statement->fetch(PDO::FETCH_ASSOC);

    // ── 재사용 탐지: 이미 폐기된 토큰이 다시 들어옴 = 탈취 의심 ──
    if ($token_record['revoked_at'] !== null) {
        $revoke_all_statement = $database->prepare("
            UPDATE refresh_tokens
            SET revoked_at = NOW()
            WHERE user_id = :user_id AND revoked_at IS NULL
        ");
        $revoke_all_statement->bindParam(':user_id', $token_record['user_id'], PDO::PARAM_INT);
        $revoke_all_statement->execute();

        $database->commit();

        error_log('[refresh] 폐기된 refresh token 재사용 감지 - user_id=' . $token_record['user_id']);
        sendError('보안을 위해 다시 로그인해주세요', 401);
    }

    if (strtotime($token_record['expires_at']) < time()) {
        $database->rollBack();
        sendError('로그인이 만료되었습니다. 다시 로그인해주세요', 401);
    }

    // ── 현재 토큰 폐기 + 새 토큰 쌍 발급 (rotation) ──
    $revoke_current_statement = $database->prepare("
        UPDATE refresh_tokens SET revoked_at = NOW() WHERE refresh_token_id = :id
    ");
    $revoke_current_statement->bindParam(':id', $token_record['refresh_token_id'], PDO::PARAM_INT);
    $revoke_current_statement->execute();

    $token_pair = issue_token_pair($database, (int)$token_record['user_id']);

    $database->commit();

    sendSuccess('토큰이 재발급되었습니다', [
        'access_token' => $token_pair['access_token'],
        'refresh_token' => $token_pair['refresh_token'],
    ]);

} catch (PDOException $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    sendError('토큰 재발급 중 오류가 발생했습니다: ' . $exception->getMessage(), 500);
}
?>