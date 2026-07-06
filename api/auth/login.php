<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// JSON 입력 받기
$input = json_decode(file_get_contents('php://input'), true);

// 필수 필드 확인
if (empty($input['email']) || empty($input['password'])) {
    sendError('이메일과 비밀번호를 입력해주세요');
}

$email = trim($input['email']);
$password = $input['password'];

// DB 연결
$database = new Database();
$db = $database->getConnection();

// 데이터베이스 연결 확인
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // 사용자 조회
    $query = "SELECT 
                user_id, 
                user_email, 
                user_password, 
                user_nickname, 
                user_profile_image, 
                user_gender, 
                user_birthday, 
                user_location, 
                user_phone_number,
                user_manner_score, 
                user_participation_count,
                user_status,
                user_email_verified
              FROM users 
              WHERE user_email = :email";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
   // 변경
if ($stmt->rowCount() === 0) {
    // 탈퇴 시 user_email이 NULL로 바뀌므로, 원래 이메일은 
    // user_withdrawn_email에서만 찾을 수 있다.
    // 활성 계정 조회에서 이미 못 찾은 경우에만 확인하므로,
    // 신규 가입자가 과거 탈퇴자의 이메일을 재사용한 경우와 충돌하지 않는다.
    $withdrawn_check_query = "SELECT user_id FROM users 
                               WHERE user_withdrawn_email = :email 
                               AND user_status = '탈퇴' 
                               LIMIT 1";
    $withdrawn_check_stmt = $db->prepare($withdrawn_check_query);
    $withdrawn_check_stmt->bindParam(':email', $email);
    $withdrawn_check_stmt->execute();

    if ($withdrawn_check_stmt->rowCount() > 0) {
        sendError('탈퇴한 계정입니다');
    }

    sendError('가입되지 않은 이메일입니다');
}
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 계정 활성화 확인
   if($user['user_status'] !== '활성') {
    $msg = match($user['user_status']) {
        '탈퇴' => "탈퇴한 계정입니다",
        '정지' => "정지된 계정입니다" ,
        default => "접근이 제한된 계정입니다",
    };
    sendError($msg);
   }
    
    // 비밀번호 확인
    if (!password_verify($password, $user['user_password'])) {
        sendError('비밀번호가 일치하지 않습니다');
    }
    
    // 비밀번호 제거 (클라이언트로 전송 X)
    unset($user['user_password']);
    
    // 숫자(0/1)를 boolean으로 변환
    $user['user_email_verified'] = (bool)$user['user_email_verified'];
    
     // Access + Refresh 토큰 쌍 발급
     $token_pair = issue_token_pair($db, $user['user_id']);
     $user['token'] = $token_pair['access_token'];
     $user['refresh_token'] = $token_pair['refresh_token'];
    
    // 성공 응답
    sendSuccess('로그인 성공', $user);
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>