<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

if (empty($_GET['email'])) {
    sendError('이메일을 입력해주세요');
}

$email = trim($_GET['email']);

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
    $query = "SELECT user_id FROM users WHERE user_email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 이 이메일이 소셜 계정에 연결되어 있는지 확인
        $check_social_query = "SELECT provider FROM social_accounts WHERE user_id = :user_id LIMIT 1";
        $check_social_stmt = $db->prepare($check_social_query);
        $check_social_stmt->bindParam(':user_id', $existing_user['user_id'], PDO::PARAM_INT);
        $check_social_stmt->execute();

        if ($check_social_stmt->rowCount() > 0) {
            $social_info = $check_social_stmt->fetch(PDO::FETCH_ASSOC);
            $provider_name = match ($social_info['provider']) {
                'kakao' => '카카오',
                'google' => '구글',
                default => $social_info['provider'],
            };
            sendResponse(false, "이미 {$provider_name} 계정으로 가입되어 있습니다. {$provider_name} 로그인을 이용해주세요", [
                'available' => false,
                'social_provider' => $social_info['provider']
            ]);
        }

        sendResponse(false, '이미 가입된 이메일입니다', ['available' => false]);
    } else {
        sendResponse(true, '사용 가능한 이메일입니다', ['available' => true]);
    }
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>