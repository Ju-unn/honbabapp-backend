<?php
/**
 * 프로필 조회 API
 * GET /api/user/profile.php?user_id=1
 *
 * 변경사항 (체험단 도입):
 * - SELECT에 user_instagram_id, user_blog_id 추가
 * - 기존 필드 및 응답 구조 하위 호환 유지
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

if (empty($_GET['user_id'])) {
    sendError('user_id가 필요합니다.');
}

$user_id = intval($_GET['user_id']);

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다.', 500);
}

try {
    $query = "SELECT
                user_id,
                user_nickname,
                user_profile_image,
                user_status_message,
                user_manner_score,
                user_participation_count,
                user_email,
                user_birthday,
                user_gender,
                push_enabled,
                user_instagram_id,
                user_blog_id
              FROM users
              WHERE user_id = :user_id";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendError('존재하지 않는 사용자입니다.', 404);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 타입 변환 (기존 그대로)
    $user['user_manner_score']        = (float)$user['user_manner_score'];
    $user['user_participation_count'] = (int)$user['user_participation_count'];
    $user['push_enabled']             = (int)$user['push_enabled'];

    // SNS ID null 처리 (없으면 빈 문자열 대신 null 유지)
    $user['user_instagram_id'] = $user['user_instagram_id'] ?? null;
    $user['user_blog_id']      = $user['user_blog_id'] ?? null;

    sendSuccess('프로필 조회 성공', $user);

} catch (PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>