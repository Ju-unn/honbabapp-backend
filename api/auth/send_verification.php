<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['email'])) {
    sendError('이메일을 입력해주세요');
}

$email = trim($input['email']);

// 이메일 형식 검증
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError('올바른 이메일 형식이 아닙니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

// 데이터베이스 연결 확인
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // 이미 가입된 이메일인지 확인
    $check_query = "SELECT user_id , user_status FROM users WHERE user_email = :email";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':email', $email);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        $user = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($user['user_status'] === '활성') {
            sendError('이미 가입된 이메일입니다');
        }
        if ($user['user_status'] === '정지') {
            sendError('정지된 계정입니다');
        }
        // 참고: 탈퇴 시 user_email이 NULL 처리되므로 '탈퇴' 상태가 여기서 조회될 일은
        // 사실상 없음. 조회되더라도 통과 → 인증코드 발송 허용 (재가입 = 복원 경로)
    }
    
    // 6자리 랜덤 인증 코드 생성
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // 기존 인증 코드 삭제 (같은 이메일)
    $delete_query = "DELETE FROM email_verifications WHERE email = :email";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':email', $email);
    $delete_stmt->execute();

    // 새 인증 코드 저장 — 만료시각(5분)은 verify_code.php의 NOW() 비교와 같은 DB 시계로 계산
    $insert_query = "INSERT INTO email_verifications (email, code, expires_at) VALUES (:email, :code, DATE_ADD(NOW(), INTERVAL 5 MINUTE))";
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':email', $email);
    $insert_stmt->bindParam(':code', $code);
    $insert_stmt->execute();
    
    // ===== 실제로는 여기서 이메일 발송 =====
    // mail() 함수나 PHPMailer 사용
    // 개발 단계에서는 코드를 응답으로 반환
    
    sendSuccess('인증 코드가 발송되었습니다', [
        'code' => $code, // 실제 운영에서는 삭제해야 함!
        'expires_in' => 300 // 5분 (초 단위)
    ]);
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>