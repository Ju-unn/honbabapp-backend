<?php
require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

if (empty($_GET['nickname'])) {
    sendError('닉네임을 입력해주세요');
}

$nickname = trim($_GET['nickname']);

// 닉네임 길이 검증
if (strlen($nickname) < 2 || strlen($nickname) > 10) {
    sendError('닉네임은 2-10자 이내로 입력해주세요');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

// 데이터베이스 연결 확인
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $query = "SELECT user_id FROM users WHERE user_nickname = :nickname";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':nickname', $nickname);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        sendResponse(false, '이미 사용중인 닉네임입니다', ['available' => false]);
    } else {
        sendResponse(true, '사용 가능한 닉네임입니다', ['available' => true]);
    }
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>