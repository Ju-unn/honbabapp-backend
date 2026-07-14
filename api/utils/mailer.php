<?php
/**
 * PHPMailer 기반 공용 메일 발송 유틸
 *
 * 사용 전 서버에서 설치 필요:
 *   composer require phpmailer/phpmailer
 *
 * .env 필요 키:
 *   SMTP_HOST       (예: smtp.gmail.com)
 *   SMTP_PORT       (예: 587)
 *   SMTP_SECURE     (tls 또는 ssl)
 *   SMTP_USER       (예: example@gmail.com — Gmail은 앱 비밀번호 필요)
 *   SMTP_PASS       (Gmail 앱 비밀번호 16자리)
 *   SMTP_FROM_EMAIL (보내는 주소, 생략 시 SMTP_USER)
 *   SMTP_FROM_NAME  (보내는 이름, 생략 시 '혼밥앱')
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * .env 로더 — Database::loadEnv()와 동일한 2개 경로.
 * 대부분 Database 생성 시 이미 로드되지만, 단독 사용에도 안전하도록 방어적으로 로드한다.
 */
function loadMailerEnv() {
    if (getenv('SMTP_HOST') !== false && getenv('SMTP_HOST') !== '') {
        return; // 이미 로드됨
    }

    $paths = [
        '/var/www/html/.env',
        dirname(__DIR__, 2) . '/.env'
    ];

    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim(trim($parts[1]), "\"'");

            if ($name === '') {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        break;
    }
}

/**
 * 메일 발송
 *
 * @param string $to       받는 이메일 주소
 * @param string $subject  제목
 * @param string $htmlBody HTML 본문
 * @return bool 성공 여부 (실패 시 error_log 기록 후 false)
 */
function sendMail($to, $subject, $htmlBody) {
    loadMailerEnv();

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER');
        $mail->Password   = getenv('SMTP_PASS');
        $mail->Port       = intval(getenv('SMTP_PORT') ?: 587);

        $secure = getenv('SMTP_SECURE') ?: 'tls';
        $mail->SMTPSecure = ($secure === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS      // 465
            : PHPMailer::ENCRYPTION_STARTTLS;  // 587

        // 한글 제목/본문 깨짐 방지
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USER');
        $fromName  = getenv('SMTP_FROM_NAME') ?: '혼밥앱';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('메일 발송 실패 (' . $to . '): ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * 인증코드 메일 본문 템플릿 (회원가입 / 비밀번호 재설정 공용)
 *
 * @param string $code    6자리 인증코드
 * @param string $purpose 용도 문구 (예: '회원가입', '비밀번호 재설정')
 */
function buildVerificationCodeEmail($code, $purpose = '회원가입') {
    return "
    <div style='max-width:480px;margin:0 auto;padding:24px;font-family:sans-serif;color:#333;'>
        <h2 style='color:#FF6B35;'>혼밥앱</h2>
        <p>{$purpose} 인증코드입니다. 아래 코드를 5분 안에 입력해주세요.</p>
        <div style='background:#f5f5f5;border-radius:12px;padding:20px;text-align:center;margin:20px 0;'>
            <span style='font-size:32px;font-weight:bold;letter-spacing:8px;'>{$code}</span>
        </div>
        <p style='color:#888;font-size:13px;'>본인이 요청하지 않았다면 이 메일을 무시해주세요.</p>
    </div>";
}

/**
 * 임시 비밀번호 안내 메일 본문 템플릿
 *
 * @param string $tempPassword 발급된 임시 비밀번호
 */
function buildTempPasswordEmail($tempPassword) {
    return "
    <div style='max-width:480px;margin:0 auto;padding:24px;font-family:sans-serif;color:#333;'>
        <h2 style='color:#FF6B35;'>혼밥앱</h2>
        <p>임시 비밀번호가 발급되었습니다. 아래 비밀번호로 로그인해주세요.</p>
        <div style='background:#f5f5f5;border-radius:12px;padding:20px;text-align:center;margin:20px 0;'>
            <span style='font-size:24px;font-weight:bold;letter-spacing:2px;'>{$tempPassword}</span>
        </div>
        <p>로그인 후 <b>프로필 &gt; 계정/정보 관리</b>에서 반드시 비밀번호를 변경해주세요.</p>
        <p style='color:#888;font-size:13px;'>본인이 요청하지 않았다면 고객센터로 문의해주세요.</p>
    </div>";
}
?>
