<?php
require_once '../config/database.php';
require_once '../utils/response.php';

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// JSON 입력 받기
$input = json_decode(file_get_contents('php://input'), true);

// 필수 필드 확인
if (empty($input['user_id']) || empty($input['current_password'])) {
    sendError('user_id, current_password가 모두 필요합니다');
}

$user_id = (int)$input['user_id'];
$current_password = $input['current_password'];

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
  sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
  // user_id로 현재 저장된 비밀번호 해시 조회
  $query = 'SELECT user_password FROM users WHERE user_id = :user_id';
  $stmt = $db->prepare($query);
  $stmt->bindParam(':user_id' , $user_id , PDO::PARAM_INT);
  $stmt->execute();

  if ($stmt->rowCount() === 0) {
    sendError('존재하지 않는 사용자 입니다.', 404);
  }

  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  
  // 입력한 비밀번호와 db의 해시 비교
  if (!password_verify($current_password, $user['user_password'])) {
    sendError('현재 비밀번호가 일치하지 않습니다');
  }

   // 일치하면 성공
   sendSuccess('현재 비밀번호가 확인되었습니다', null); 

} catch(PDOException $e) {
  sendError('데이터베이스 오류 : ' . $e->getMessage(), 500);
}
?>