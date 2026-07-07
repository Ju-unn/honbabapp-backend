<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 1. DB 먼저 연결 (.env 로딩 보장)
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

// 2. JWT 토큰으로 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
$user_id = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);

// 3. 선택적 필드들 - 요청에 포함된 것만 업데이트
$nickname = isset($input['nickname']) ? trim($input['nickname']) : null;
$phone_number = isset($input['phone_number']) ? trim($input['phone_number']) : null;
$location = isset($input['location']) ? trim($input['location']) : null;
$gender = isset($input['gender']) ? trim($input['gender']) : null;
$birthday = isset($input['birthday']) ? trim($input['birthday']) : null;
$profile_image = isset($input['profile_image']) ? trim($input['profile_image']) : null;

// 닉네임 길이 검증 (기존 check_nickname.php와 동일한 규칙)
if ($nickname !== null && (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 10)) {
    sendError('닉네임은 2-10자 이내로 입력해주세요');
}

// 성별 값 검증 (DB enum과 일치해야 함)
$allowed_genders = ['남성', '여성', '기타'];
if ($gender !== null && !in_array($gender, $allowed_genders)) {
    sendError('성별 값이 올바르지 않습니다');
}

try {
    // 4. 닉네임 중복 확인 (자기 자신은 제외)
    if ($nickname !== null) {
        $check_nickname_query = "SELECT user_id FROM users 
                                  WHERE user_nickname = :nickname 
                                  AND user_id != :user_id";
        $check_nickname_stmt = $db->prepare($check_nickname_query);
        $check_nickname_stmt->bindParam(':nickname', $nickname);
        $check_nickname_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $check_nickname_stmt->execute();

        if ($check_nickname_stmt->rowCount() > 0) {
            sendError('이미 사용중인 닉네임입니다');
        }
    }

       // 4b. 전화번호 중복 확인 (자기 자신은 제외)
       // 탈퇴 계정의 번호도 차단: 소셜 신규가입 + 탈퇴 번호 조합으로
       // 계정을 새로 만들어 매너점수를 초기화하는 악용 방지
       if ($phone_number !== null) {
        $check_phone_query = "SELECT user_id, user_status FROM users
                               WHERE user_phone_number = :phone_number
                               AND user_id != :user_id";
        $check_phone_stmt = $db->prepare($check_phone_query);
        $check_phone_stmt->bindParam(':phone_number', $phone_number);
        $check_phone_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $check_phone_stmt->execute();

        if ($check_phone_stmt->rowCount() > 0) {
            $phone_owner = $check_phone_stmt->fetch(PDO::FETCH_ASSOC);
            if ($phone_owner['user_status'] === '탈퇴') {
                sendError('이전에 가입한 계정이 있는 전화번호입니다. 기존 계정으로 로그인하면 복원됩니다');
            }
            sendError('이미 사용중인 전화번호입니다');
        }
    }

    // 5. 동적 UPDATE 쿼리 (요청에 포함된 필드만 업데이트)
    $set_parts = [];
    $params = [];

    if ($nickname !== null) {
        $set_parts[] = "user_nickname = :nickname";
        $params[':nickname'] = $nickname;
    }
    if ($phone_number !== null) {
        $set_parts[] = "user_phone_number = :phone_number";
        $params[':phone_number'] = $phone_number;
    }
    if ($location !== null) {
        $set_parts[] = "user_location = :location";
        $params[':location'] = $location;
    }
    if ($gender !== null) {
        $set_parts[] = "user_gender = :gender";
        $params[':gender'] = $gender;
    }
    if ($birthday !== null) {
        $set_parts[] = "user_birthday = :birthday";
        $params[':birthday'] = $birthday;
    }
    if ($profile_image !== null) {
        $set_parts[] = "user_profile_image = :profile_image";
        $params[':profile_image'] = $profile_image;
    }

    if (empty($set_parts)) {
        sendError('수정할 항목이 없습니다');
    }

    $set_clause = implode(', ', $set_parts);
    $update_query = "UPDATE users SET $set_clause WHERE user_id = :user_id";

    $update_stmt = $db->prepare($update_query);
    foreach ($params as $key => $value) {
        $update_stmt->bindValue($key, $value);
    }
    $update_stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $update_stmt->execute();

    // 6. 갱신된 사용자 정보 조회해서 응답
    $user_query = "SELECT user_id, user_email, user_nickname, user_profile_image, 
                          user_gender, user_birthday, user_location, user_phone_number,
                          user_manner_score, user_participation_count, user_status,
                          user_email_verified
                   FROM users WHERE user_id = :user_id";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $user_stmt->execute();
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    $user['user_email_verified'] = (bool)$user['user_email_verified'];

    sendSuccess('추가 정보가 저장되었습니다', $user);

} catch (PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}

?>