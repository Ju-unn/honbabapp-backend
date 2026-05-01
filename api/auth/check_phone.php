<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

if (empty($_GET['phone'])) {
    sendError('전화번호를 입력해주세요');
}

$phone = trim($_GET['phone']);

// 전화번호 형식 검증 (010-1234-5678)
if (!preg_match('/^010-\d{4}-\d{4}$/', $phone)) {
    sendError('올바른 전화번호 형식이 아닙니다 (010-1234-5678)');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

// 데이터베이스 연결 확인
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
} 

try {
    $query = "SELECT user_id , user_status FROM users WHERE user_phone_number = :phone";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':phone', $phone);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user['user_status'] === '탈퇴') {
            // 탈퇴 계정 → 사용 가능 + 복원 플래그
            sendResponse(true, '사용 가능한 전화번호입니다', [
            'available' => true,
            'is_restored' => true
        ]);
        }  else {
            // 활성/정지 계정 → 사용 불가
            sendResponse(false, '이미 사용중인 전화번호입니다', [
                'available' => false,
                'is_restored' => false
            ]);
        }
    } else {
        // 없는 번호 → 신규 가입
        sendResponse(true, '사용 가능한 전화번호입니다', [
        'available' => true,
        'is_restored' => false
    ]);
    }
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}

?>