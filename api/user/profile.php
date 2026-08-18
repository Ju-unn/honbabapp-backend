<?php
/**
 * 프로필 조회 API
 * GET /api/user/profile.php?user_id=1
 *
 * 변경사항 (체험단 도입):
 * - SELECT에 user_instagram_id, user_blog_id, user_youtube_id 추가
 * - 기존 필드 및 응답 구조 하위 호환 유지
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

// 로그인한 사용자만 조회 가능 (비로그인 상태로 user_id를 바꿔가며
// 이메일/생일 등을 긁어가는 것 방지)
$requester_id = get_user_id_from_token();

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
    // social_provider: 소셜 로그인 계정이면 'kakao'/'google', 일반 계정이면 NULL
    // (앱이 계정 관리 화면에서 비밀번호 변경 메뉴 노출 여부를 결정하는 데 사용)
    $query = "SELECT
                u.user_id,
                u.user_nickname,
                u.user_profile_image,
                u.user_status_message,
                u.user_manner_score,
                u.user_participation_count,
                u.user_email,
                u.user_birthday,
                u.user_gender,
                u.push_enabled,
                u.user_instagram_id,
                u.user_blog_id,
                u.user_youtube_id,
                u.user_status,
                sa.provider AS social_provider
              FROM users u
              LEFT JOIN social_accounts sa ON sa.user_id = u.user_id
              WHERE u.user_id = :user_id
              LIMIT 1";

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
    $user['user_youtube_id']   = $user['user_youtube_id'] ?? null;
    $user['social_provider']   = $user['social_provider'] ?? null;

    // 탈퇴한 사용자 처리:
    // - 닉네임은 "탈퇴한 유저"로 표시
    // - 상태메시지/SNS ID 등 탈퇴 시 정리되지 않는 개인 정보는 응답에서 제거
    // - user_status 필드로 앱에서 탈퇴 여부 판별 가능 (채팅/후기 버튼 숨김 등)
    if ($user['user_status'] === '탈퇴') {
        $user['user_nickname']       = '탈퇴한 유저';
        $user['user_status_message'] = null;
        $user['user_instagram_id']   = null;
        $user['user_blog_id']        = null;
        $user['user_youtube_id']     = null;
        $user['social_provider']     = null;
        $user['user_birthday']       = null;
        $user['user_gender']         = null;
    }

    // 타인 프로필 조회 시 민감 정보 제거
    // (이메일/생일/성별/푸시설정/로그인수단은 본인의 계정관리 화면에서만 필요.
    //  타인 프로필 화면은 닉네임/사진/상태메시지/매너점수/참여횟수만 사용)
    if ($requester_id !== $user_id) {
        unset(
            $user['user_email'],
            $user['user_birthday'],
            $user['user_gender'],
            $user['push_enabled'],
            $user['social_provider']
        );
    }

    sendSuccess('프로필 조회 성공', $user);

} catch (PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>