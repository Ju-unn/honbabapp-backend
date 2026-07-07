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
// 복원 판정용 (선택): 가입에 사용 중인 이메일. 탈퇴 계정의 원본 이메일과 일치하면
// 그 계정의 전화번호를 복원 절차에서 그대로/변경해서 쓸 수 있다.
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

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
    $query = "SELECT user_id , user_status , user_withdrawn_email FROM users WHERE user_phone_number = :phone";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':phone', $phone);
    $stmt->execute();
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 1) 활성/정지 계정이 쓰는 번호 → 무조건 사용 불가
    foreach ($owners as $owner) {
        if ($owner['user_status'] !== '탈퇴') {
            sendResponse(false, '이미 사용중인 전화번호입니다', [
                'available' => false,
                'is_restored' => false
            ]);
        }
    }

    if (count($owners) > 0) {
        // 2) 탈퇴 계정의 번호
        //    - 가입 이메일이 그 계정의 원본 이메일(user_withdrawn_email)과 일치 → 복원 절차이므로 허용
        //    - 새 이메일 → 차단: 탈퇴 번호로 계정을 무한 생성해 매너점수를 초기화하는 악용 방지
        if ($email !== '') {
            foreach ($owners as $owner) {
                if ($owner['user_withdrawn_email'] === $email) {
                    sendResponse(true, '이전에 사용하던 계정의 전화번호입니다. 가입을 완료하면 계정이 복원됩니다', [
                        'available' => true,
                        'is_restored' => true
                    ]);
                }
            }
        }

        sendResponse(false, '이전에 가입한 이메일이 있습니다. 이전에 사용하던 이메일로 가입해주세요', [
            'available' => false,
            'is_restored' => false
        ]);
    }

    // 3) 없는 번호 → 신규 가입
    sendResponse(true, '사용 가능한 전화번호입니다', [
        'available' => true,
        'is_restored' => false
    ]);
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}

?>