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
// (선택) 함께 입력된 전화번호 — 탈퇴 계정의 번호인데 다른 이메일로 가입하려는 경우를
// 이메일 확인 시점에 미리 안내하기 위해 사용
$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';

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

        sendResponse(false, '이미 가입된 이메일입니다', ['available' => false, 'is_restored' => false]);
    } else {
        // 탈퇴 계정의 원본 이메일인지 확인 → 재가입 시 계정 복원 안내
        // (탈퇴 시 user_email은 NULL 처리되고 원본은 user_withdrawn_email에 보관됨)
        $withdrawn_query = "SELECT user_id FROM users
                            WHERE user_withdrawn_email = :email AND user_status = '탈퇴'
                            LIMIT 1";
        $withdrawn_stmt = $db->prepare($withdrawn_query);
        $withdrawn_stmt->bindParam(':email', $email);
        $withdrawn_stmt->execute();

        if ($withdrawn_stmt->rowCount() > 0) {
            sendResponse(true, '이전에 사용하던 계정이 있습니다. 가입을 완료하면 계정이 복원됩니다', [
                'available' => true,
                'is_restored' => true
            ]);
        }

        // 여기 도달 = 이 이메일은 탈퇴 계정의 원본 이메일이 아님.
        // 그런데 함께 넘어온 전화번호가 탈퇴 계정 소유라면 → 새 이메일 가입은
        // 매너점수 초기화 악용이 되므로 기존 이메일 사용을 유도
        if ($phone !== '') {
            $phone_owner_query = "SELECT user_id FROM users
                                  WHERE user_phone_number = :phone AND user_status = '탈퇴'
                                  LIMIT 1";
            $phone_owner_stmt = $db->prepare($phone_owner_query);
            $phone_owner_stmt->bindParam(':phone', $phone);
            $phone_owner_stmt->execute();

            if ($phone_owner_stmt->rowCount() > 0) {
                sendResponse(false, '이 전화번호로 가입한 계정이 있습니다. 기존에 사용하던 이메일을 입력해주세요', [
                    'available' => false,
                    'is_restored' => false
                ]);
            }
        }

        sendResponse(true, '사용 가능한 이메일입니다', ['available' => true, 'is_restored' => false]);
    }
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>