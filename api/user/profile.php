<?php

require_once '../config/database.php';
require_once '../utils/response.php';

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다' , 405);
}

// URL 피라미터에서 user_id 받기
if (empty($_GET['user_id'])){
    sendError('user_id가 필요합니다.');
}

// intval() 문자열 "3" 정수 3으로 변환
$user_id = intval($_GET['user_id']);

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다.' , 500);
}

try {
    $query = "SELECT
                user_id ,
                user_nickname ,
                user_profile_image ,
                user_status_message ,
                user_manner_score ,
                user_participation_count ,
                user_email ,
                user_birthday ,
                user_gender ,
                push_enabled
                FROM users
                WHERE user_id = :user_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id , PDO::PARAM_INT);
$stmt->execute();

// rowCount() 조회된 행 수 0이면 해당 유저 없음
if ($stmt->rowCount() === 0) {
    sendError('존재하지 않는 사용자 입니다.' , 404);
}

$user = $stmt->fetch(PDO::FETCH_ASSOC);

// manner_score 소수점이므로 float 변환
$user['user_manner_score'] = (float)$user['user_manner_score'];
$user['user_participation_count'] = (int)$user['user_participation_count'];
// push_enabled 는 tinyint(1) → 0/1 정수로 변환 (앱 알림 설정 화면에서 사용)
$user['push_enabled'] = (int)$user['push_enabled'];

sendSuccess('프로필 조회 성공' , $user);

} catch(PDOException $e) {
    sendError('데이터베이스 오류' . $e->getMessage() , 500);
}

?>