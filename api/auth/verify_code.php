<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['email']) || empty($input['code'])) {
    sendError('이메일과 인증 코드를 입력해주세요');
}

$email = trim($input['email']);
$code = trim($input['code']);

// 용도 구분 (선택 입력, 기본 'signup') — 재설정용 코드로 가입 인증을 통과할 수 없게 분리
$purpose = isset($input['purpose']) && trim($input['purpose']) !== ''
    ? trim($input['purpose'])
    : 'signup';

if (!in_array($purpose, ['signup', 'password_reset'])) {
    sendError('purpose는 signup, password_reset 중 하나여야 합니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

// 데이터베이스 연결 확인
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $query = "SELECT * FROM email_verifications
              WHERE email = :email
              AND code = :code
              AND purpose = :purpose
              AND is_verified = FALSE
              AND expires_at > NOW()
              ORDER BY created_at DESC
              LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':code', $code);
    $stmt->bindParam(':purpose', $purpose);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        sendError('인증 코드가 올바르지 않거나 만료되었습니다');
    }
    
    // 인증 완료 처리
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);
    $update_query = "UPDATE email_verifications 
                     SET is_verified = TRUE 
                     WHERE verification_id = :id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':id', $verification['verification_id']);
    $update_stmt->execute();
    
    sendSuccess('이메일 인증이 완료되었습니다', ['verified' => true]);
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>