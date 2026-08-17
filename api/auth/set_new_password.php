<?php
/**
 * 비밀번호 재설정 - 새 비밀번호 직접 설정 API
 * POST /api/auth/set_new_password.php
 *
 * 입력: { "email": "user@example.com", "code": "123456", "new_password": "abcd1234" }
 *
 * 흐름:
 * 1) purpose='password_reset' 인증코드 검증 (send_password_reset.php가 발급한 코드, 만료 전만)
 * 2) 새 비밀번호를 bcrypt 해시로 저장
 * 3) 인증 행 삭제 — 1회용 보장
 *
 * reset_password.php(임시 비밀번호 메일 발송 방식)를 대체한다.
 */
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['email']) || empty($input['code']) || empty($input['new_password'])) {
    sendError('이메일, 인증 코드, 새 비밀번호를 모두 입력해주세요');
}

$email = trim($input['email']);
$code = trim($input['code']);
$new_password = $input['new_password'];

// 새 비밀번호 길이 검증 (change_password.php와 동일 규칙)
if (mb_strlen($new_password) < 8) {
    sendError('새 비밀번호는 8자 이상이어야 합니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // 1. 대상 회원 확인 (발송 시와 동일 게이트)
    $user_query = "SELECT user_id, user_status FROM users
                   WHERE user_email = :email
                   LIMIT 1";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bindParam(':email', $email);
    $user_stmt->execute();

    if ($user_stmt->rowCount() === 0) {
        sendError('가입되지 않은 이메일입니다');
    }

    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if ($user['user_status'] !== '활성') {
        sendError('가입되지 않은 이메일입니다');
    }

    // 소셜 로그인 계정 차단
    $social_query = "SELECT provider FROM social_accounts WHERE user_id = :user_id LIMIT 1";
    $social_stmt = $db->prepare($social_query);
    $social_stmt->bindParam(':user_id', $user['user_id'], PDO::PARAM_INT);
    $social_stmt->execute();

    if ($social_stmt->rowCount() > 0) {
        $social_info = $social_stmt->fetch(PDO::FETCH_ASSOC);
        $provider_name = match ($social_info['provider']) {
            'kakao' => '카카오',
            'google' => '구글',
            default => $social_info['provider'],
        };
        sendError("{$provider_name} 소셜 로그인 계정입니다. {$provider_name} 로그인을 이용해주세요");
    }

    // 2. 인증코드 검증 (purpose='password_reset' 행만, 만료 전만)
    $verify_query = "SELECT verification_id FROM email_verifications
                     WHERE email = :email
                     AND code = :code
                     AND purpose = 'password_reset'
                     AND expires_at > NOW()
                     ORDER BY created_at DESC
                     LIMIT 1";
    $verify_stmt = $db->prepare($verify_query);
    $verify_stmt->bindParam(':email', $email);
    $verify_stmt->bindParam(':code', $code);
    $verify_stmt->execute();

    if ($verify_stmt->rowCount() === 0) {
        sendError('인증 코드가 올바르지 않거나 만료되었습니다');
    }

    $verification = $verify_stmt->fetch(PDO::FETCH_ASSOC);

    // 3. 새 비밀번호로 교체
    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

    $update_query = "UPDATE users
                     SET user_password = :password, user_updated_at = NOW()
                     WHERE user_id = :user_id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':password', $hashed_password);
    $update_stmt->bindParam(':user_id', $user['user_id'], PDO::PARAM_INT);
    $update_stmt->execute();

    // 인증 행 삭제 — 1회용 보장
    $delete_query = "DELETE FROM email_verifications WHERE verification_id = :id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':id', $verification['verification_id'], PDO::PARAM_INT);
    $delete_stmt->execute();

    sendSuccess('비밀번호가 변경되었습니다. 새 비밀번호로 로그인해주세요');

} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>
