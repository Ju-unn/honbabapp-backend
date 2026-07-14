<?php
/**
 * 아이디(이메일) 찾기 API
 * POST /api/auth/find_email.php
 *
 * 입력: { "phone_number": "01012345678" }
 * 응답: { "masked_email": "ho***@naver.com" }
 *
 * 이 앱은 이메일이 곧 아이디라서 이메일 인증으로 아이디를 찾는 건 순환이 됨.
 * → 휴대폰번호로 조회해서 마스킹된 이메일을 즉시 보여준다 (인증코드 없음).
 */
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['phone_number'])) {
    sendError('휴대폰 번호를 입력해주세요');
}

// 하이픈/공백 제거 후 숫자만으로 비교 (앱은 하이픈 자동 생성이지만 형식과 무관하게 매칭)
$phone_number = str_replace(['-', ' '], '', trim($input['phone_number']));

if (!preg_match('/^\d{9,11}$/', $phone_number)) {
    sendError('올바른 휴대폰 번호 형식이 아닙니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

/**
 * 이메일 마스킹: 로컬파트 앞 2자만 남기고 * 처리 (ho***@naver.com)
 * 로컬파트가 2자 이하면 첫 글자만 남긴다 (a**@naver.com)
 */
function maskEmail($email) {
    $parts = explode('@', $email, 2);
    $local = $parts[0];
    $domain = isset($parts[1]) ? $parts[1] : '';

    $keep = (strlen($local) > 2) ? 2 : 1;
    $visible = substr($local, 0, $keep);
    $masked_len = max(strlen($local) - $keep, 2);

    return $visible . str_repeat('*', $masked_len) . '@' . $domain;
}

try {
    // 탈퇴 계정은 user_email이 NULL이므로 자연히 제외됨
    // DB에는 하이픈 포함(010-1234-5678) 형식으로 저장되므로 양쪽 모두 숫자만으로 비교
    $query = "SELECT user_email FROM users
              WHERE REPLACE(user_phone_number, '-', '') = :phone
              AND user_status IN ('활성', '정지')
              AND user_email IS NOT NULL
              LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':phone', $phone_number);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendError('일치하는 회원 정보가 없습니다');
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    sendSuccess('가입된 이메일을 찾았습니다', [
        'masked_email' => maskEmail($row['user_email'])
    ]);

} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>
