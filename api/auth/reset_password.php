<?php
/**
 * 임시 비밀번호 발급 API
 * POST /api/auth/reset_password.php
 *
 * 입력: { "email": "user@example.com", "code": "123456" }
 *
 * 흐름:
 * 1) purpose='password_reset' 인증코드 검증 (is_verified는 절대 세우지 않음 —
 *    register.php의 가입 게이트와 완전 분리, 검증 즉시 행 삭제로 1회용 보장)
 * 2) 10자 영숫자 임시 비밀번호 생성 (change_password.php의 8자 이상 규칙 충족)
 * 3) bcrypt 해시로 비밀번호 교체 + 인증 행 삭제 (트랜잭션)
 * 4) 임시 비밀번호는 메일로만 발송 — JSON 응답에 절대 포함하지 않음
 *    메일 발송 실패 시 트랜잭션 롤백 → 기존 비밀번호 유지 (락아웃 방지)
 */
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['email']) || empty($input['code'])) {
    sendError('이메일과 인증 코드를 입력해주세요');
}

$email = trim($input['email']);
$code = trim($input['code']);

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

/**
 * 10자 영숫자 임시 비밀번호 생성
 * (헷갈리기 쉬운 0/O, 1/l/I 는 제외)
 */
function generateTempPassword() {
    $pool = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 10; $i++) {
        $password .= $pool[random_int(0, strlen($pool) - 1)];
    }
    return $password;
}

try {
    // 1. 대상 회원 확인 (발송 시와 동일 게이트)
    $user_query = "SELECT user_id, user_status, user_password FROM users
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

    // 소셜 로그인 계정 차단 — user_password는 소셜 가입 시에도 임의 해시로
    // 채워지므로(NOT NULL 제약) social_accounts 존재 여부로 판별해야 한다
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

    // 3. 임시 비밀번호 생성 + 교체 (트랜잭션)
    $temp_password = generateTempPassword();
    $hashed_password = password_hash($temp_password, PASSWORD_BCRYPT);

    $db->beginTransaction();

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

    // 4. 임시 비밀번호 메일 발송 — 실패 시 롤백 (기존 비밀번호 유지)
    $mail_sent = sendMail(
        $email,
        '[혼밥앱] 임시 비밀번호 안내',
        buildTempPasswordEmail($temp_password)
    );

    if (!$mail_sent) {
        $db->rollBack();
        sendError('메일 발송에 실패했습니다. 잠시 후 다시 시도해주세요', 500);
    }

    $db->commit();

    sendSuccess('임시 비밀번호가 이메일로 발송되었습니다. 로그인 후 비밀번호를 변경해주세요');

} catch(PDOException $e) {
    if ($db !== null && $db->inTransaction()) {
        $db->rollBack();
    }
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>
