<?php
/**
 * 비밀번호 재설정 인증코드 발송 API
 * POST /api/auth/send_password_reset.php
 *
 * 입력: { "email": "user@example.com" }
 *
 * 가입 인증(send_verification.php)과 반대 게이트:
 * - '활성' 상태의 기존 회원만 허용
 * - 소셜 로그인 계정(user_password IS NULL)은 재설정할 비밀번호가 없으므로 거부
 * - purpose='password_reset'으로 저장 → 가입 게이트(purpose='signup')와 완전 분리
 */
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['email'])) {
    sendError('이메일을 입력해주세요');
}

$email = trim($input['email']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError('올바른 이메일 형식이 아닙니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // 가입된 활성 회원인지 확인
    $check_query = "SELECT user_id, user_status, user_password FROM users
                    WHERE user_email = :email
                    LIMIT 1";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':email', $email);
    $check_stmt->execute();

    if ($check_stmt->rowCount() === 0) {
        sendError('가입되지 않은 이메일입니다');
    }

    $user = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($user['user_status'] === '정지') {
        sendError('정지된 계정입니다');
    }
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

    // 6자리 랜덤 인증 코드 생성
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // 기존 재설정용 코드만 삭제 (가입용 코드는 건드리지 않음)
    $delete_query = "DELETE FROM email_verifications WHERE email = :email AND purpose = 'password_reset'";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':email', $email);
    $delete_stmt->execute();

    // 새 인증 코드 저장 (5분 만료)
    $insert_query = "INSERT INTO email_verifications (email, code, purpose, expires_at)
                     VALUES (:email, :code, 'password_reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE))";
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':email', $email);
    $insert_stmt->bindParam(':code', $code);
    $insert_stmt->execute();

    // 인증코드 메일 발송
    $mail_sent = sendMail(
        $email,
        '[혼밥앱] 비밀번호 재설정 인증코드',
        buildVerificationCodeEmail($code, '비밀번호 재설정')
    );

    if (!$mail_sent) {
        sendError('인증 메일 발송에 실패했습니다. 잠시 후 다시 시도해주세요', 500);
    }

    sendSuccess('인증 코드가 발송되었습니다', [
        'expires_in' => 300 // 5분 (초 단위)
    ]);

} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>
