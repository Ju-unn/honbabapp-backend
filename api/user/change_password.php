<?php

require_once '../config/database.php';
require_once '../utils/response.php';
 
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input') , true);

// 필수 데이터
if (empty($input['user_id']) || empty($input['current_password']) || empty($input['new_password'])) {
    sendError('user_id, current_password, new_password가 모두 필요합니다');
}

$user_id            = intval($input['user_id']);
$current_password   = $input['current_password'];
$new_password       = $input['new_password'];

// 새 비밀번호 같이 검증
if (mb_strlen($new_password) < 8) {
    sendError ('새 비밀번호는 8자 이상이어야 합니다.');
}

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다' , 500);
}

try {
    //  1. 현재 비밀번호 확인을 위해 DB에서 해시된 비밀번호 가져오기
    $query = 'SELECT user_password FROM users WHERE user_id = :user_id';
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id' , $user_id , PDO::PARAM_INT);
    $stmt->execute();

    if($stmt->rowCount() === 0) {
        sendError('존재하지 않는 사용자 입니다.', 404);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // password_verify(입력된 평문 비밀번호, DB에 저장된 해시)
    // 비밀번호는 register.php에서 password_hash()로 해싱해서 저장됐으므로
    // 절대 == 비교하면 안 됨! 항상 password_verify() 사용
    if (!password_verify($current_password, $user['user_password'])) {
        sendError('현재 비밀번호가 일치하지 않습니다.');
    }

    // 2. 현재 비밀번호가 맞으면 새 비밀번호 해싱후 UPDATE
    // password_hash(평문, 알고리즘): 평문 → 해시 변환
    // PASSWORD_BCRYPT: 현재 가장 널리 쓰이는 안전한 해싱 알고리즘
    $new_hashed = password_hash($new_password , PASSWORD_BCRYPT);
    $update_query = "UPDATE users SET user_password = :new_password WHERE user_id = :user_id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':new_password' , $new_hashed);
    $update_stmt->bindParam(":user_id" , $user_id , PDO::PARAM_INT);
    $update_stmt->execute();

    sendSuccess('비밀번호가 변경되었습니다.');

} catch(PDOException $e) {
    sendError('데이터베이스 오류 : ' . $e->getMessage(), 500);
}



?>