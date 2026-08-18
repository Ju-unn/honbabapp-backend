<?php
/**
 * 프로필 수정 API
 * POST /api/user/update_profile.php
 *
 * 변경사항:
 * - user_instagram_id, user_blog_id, user_youtube_id 중복 검사 추가
 *   (null이면 삭제 의도 → 중복 검사 스킵)
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
 require_once '../utils/validation.php';

 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     sendError('POST 요청만 허용됩니다', 405);
 }
 
 $user_id = get_user_id_from_token();
 
 $input = json_decode(file_get_contents('php://input'), true);

// ── 기존 선택적 필드 ─────────────────────────────────────────
$user_nickname       = isset($input['user_nickname'])       ? trim($input['user_nickname'])       : null;
$user_status_message = isset($input['user_status_message']) ? trim($input['user_status_message']) : null;
$user_profile_image  = isset($input['user_profile_image'])  ? trim($input['user_profile_image'])  : null;

// ── SNS ID 신규 필드 ─────────────────────────────────────────
$user_instagram_id = isset($input['user_instagram_id']) ? trim($input['user_instagram_id']) : null;
$user_blog_id      = isset($input['user_blog_id'])      ? trim($input['user_blog_id'])      : null;
$user_youtube_id   = isset($input['user_youtube_id'])   ? trim($input['user_youtube_id'])   : null;

// 빈 문자열은 null로 변환 (삭제 의도)
if ($user_instagram_id === '') $user_instagram_id = null;
if ($user_blog_id === '')      $user_blog_id = null;
if ($user_youtube_id === '')   $user_youtube_id = null;

// 닉네임 길이 검증
if ($user_nickname !== null) {
    if (mb_strlen($user_nickname) < 2 || mb_strlen($user_nickname) > 10) {
        sendError('닉네임은 2~10자 이내로 입력해주세요');
    }

    $nickname_format_error = getNicknameFormatError($user_nickname);
    if ($nickname_format_error !== null) {
        sendError($nickname_format_error);
    }
}

// SNS ID 길이 검증
if ($user_instagram_id !== null && mb_strlen($user_instagram_id) > 50) {
    sendError('인스타그램 ID는 50자 이하로 입력해주세요');
}
if ($user_blog_id !== null && mb_strlen($user_blog_id) > 50) {
    sendError('블로그 ID는 50자 이하로 입력해주세요');
}
if ($user_youtube_id !== null && mb_strlen($user_youtube_id) > 50) {
    sendError('유튜브 ID는 50자 이하로 입력해주세요');
}

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다.', 500);
}

try {
    // 닉네임 중복 검사
    if ($user_nickname !== null) {
        $check_stmt = $db->prepare(
            'SELECT user_id FROM users WHERE user_nickname = :nickname AND user_id != :user_id'
        );
        $check_stmt->bindParam(':nickname', $user_nickname);
        $check_stmt->bindParam(':user_id',  $user_id, PDO::PARAM_INT);
        $check_stmt->execute();

        if ($check_stmt->rowCount() > 0) {
            sendError('이미 사용중인 닉네임입니다.');
        }
    }

    // ── SNS ID 중복 검사 (null이면 삭제 의도 → 스킵) ─────────

    if ($user_instagram_id !== null) {
        $insta_check = $db->prepare(
            'SELECT user_id FROM users WHERE user_instagram_id = :instagram_id AND user_id != :user_id'
        );
        $insta_check->bindParam(':instagram_id', $user_instagram_id);
        $insta_check->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $insta_check->execute();

        if ($insta_check->rowCount() > 0) {
            sendError('이미 다른 계정에서 사용 중인 인스타그램 ID입니다.');
        }
    }

    if ($user_blog_id !== null) {
        $blog_check = $db->prepare(
            'SELECT user_id FROM users WHERE user_blog_id = :blog_id AND user_id != :user_id'
        );
        $blog_check->bindParam(':blog_id', $user_blog_id);
        $blog_check->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $blog_check->execute();

        if ($blog_check->rowCount() > 0) {
            sendError('이미 다른 계정에서 사용 중인 블로그 ID입니다.');
        }
    }

    if ($user_youtube_id !== null) {
        $youtube_check = $db->prepare(
            'SELECT user_id FROM users WHERE user_youtube_id = :youtube_id AND user_id != :user_id'
        );
        $youtube_check->bindParam(':youtube_id', $user_youtube_id);
        $youtube_check->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $youtube_check->execute();

        if ($youtube_check->rowCount() > 0) {
            sendError('이미 다른 계정에서 사용 중인 유튜브 ID입니다.');
        }
    }

    // ── 동적 UPDATE ───────────────────────────────────────────
    $set_parts = [];
    $params    = [];

    if ($user_nickname !== null) {
        $set_parts[] = "user_nickname = :nickname";
        $params[':nickname'] = $user_nickname;
    }
    if ($user_status_message !== null) {
        $set_parts[] = "user_status_message = :status_message";
        $params[':status_message'] = $user_status_message;
    }
    if ($user_profile_image !== null) {
        $set_parts[] = "user_profile_image = :profile_image";
        $params[':profile_image'] = $user_profile_image;
    }
    // SNS ID (null 포함 — 빈 값으로 초기화 가능)
    if (array_key_exists('user_instagram_id', $input)) {
        $set_parts[] = "user_instagram_id = :instagram_id";
        $params[':instagram_id'] = $user_instagram_id;
    }
    if (array_key_exists('user_blog_id', $input)) {
        $set_parts[] = "user_blog_id = :blog_id";
        $params[':blog_id'] = $user_blog_id;
    }
    if (array_key_exists('user_youtube_id', $input)) {
        $set_parts[] = "user_youtube_id = :youtube_id";
        $params[':youtube_id'] = $user_youtube_id;
    }

    if (empty($set_parts)) {
        sendError('수정할 항목이 없습니다');
    }

    $set_clause = implode(', ', $set_parts);
    $query      = "UPDATE users SET {$set_clause} WHERE user_id = :user_id";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    sendSuccess('프로필이 수정되었습니다.');

} catch (PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>