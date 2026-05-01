<?php

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다' , 405);
}



// Android에서 JSON Body로 보내므로 php://input으로 읽음
// file_get_contents('php://input') = HTTP 요청 본문(body) 전체를 문자열로 읽기
// json_decode(..., true) = JSON 문자열 → PHP 배열로 변환 (두번째 인자 true = 배열, false = 객체)

$input = json_decode(file_get_contents('php://input') , true);

if (empty($input['user_id'])) {
    sendError('유저 아이디가 필요합니다.');
}
$user_id = intval($input['user_id']);


// 선택적 필드들: 요청에 포함된 것만 업데이트할 것임
// isset() = 키 자체가 존재하는지 확인 (empty는 빈 문자열도 false로 처리해서 부적합)
$user_nickname = isset($input['user_nickname']) ? trim($input['user_nickname']) : null;
$user_status_message = isset($input['user_status_message']) ? trim($input['user_status_message']) : null;
$user_profile_image = isset($input['user_profile_image']) ? trim($input['user_profile_image']) : null;

// 닉네임이 요청에 포함된 경우
if ($user_nickname !== null) {
    if (mb_strlen($user_nickname) < 2 || mb_strlen($user_nickname) > 10) {
        sendError('닉네임은 2~10자 이내로 입력해주세요');
    }
}

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터 베이스 연결에 실패 했습니다.' , 500);
}

try {
    // 닉네임 중복 검사
    if ($user_nickname !== null) {
        $check_query = 'SELECT user_id FROM users
                        WHERE user_nickname = :nickname
                        AND user_id != :user_id'; // 자기 자신은 제외

        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':nickname' , $user_nickname);
        $check_stmt->bindParam(':user_id' , $user_id , PDO::PARAM_INT);
        $check_stmt->execute();

        if ($check_stmt->rowCount() > 0) {
            sendError('이미 사용중인 닉네임입니다.');
        }
    }


    // 동적 UPDATE 쿼리
    // 요청에 포함된 필드만 업데이트하는
    // 닉네임만 바꾸고 싶으면 닉네임만 body에 담으면 됨

    $setParts = []; //"user_nickname = :nickname" 같은 SET 절 조각들
    $params = []; // bindParam에 쓸 파라미터들

    if($user_nickname !== null) {
        $setParts[] = "user_nickname = :nickname";
        $params[':nickname'] = $user_nickname; 
    }

    if($user_status_message !== null) {
        $setParts[] = "user_status_message = :status_message";
        $params[':status_message'] = $user_status_message;
    }

    if($user_profile_image != null) {
        $setParts[] ="user_profile_image = :profileImage";
        $params[':profileImage'] = $user_profile_image;
    }

    // 바꿀 필드가 하나도 없다면 에러
    if (empty($setParts)) {
        sendError('수정할 할목이 없습니다');
    }

    // implode() 배열을 특정 구분자로 이어붙임
    // ["a = :a , "b = :b"] - > "a = :a" , "b = :b"
    $setClause = implode(', ' , $setParts);
    $query = "UPDATE users SET $setClause WHERE user_id = :user_id";
    
    $stmt = $db->prepare($query);

    // 동적으로 모은 피라미터를 바인딩
    foreach ($params as $key => $value) {
        $stmt->bindValue($key , $value);
    }
    $stmt->bindValue(':user_id' , $user_id , PDO::PARAM_INT);
    $stmt->execute();

    sendSuccess('프로필이 수정되었습니다.');

} catch (PDOException $e) {
    sendError('데이터 베이스 오류 ' . $e->getMessage() , 500);
}


?>