<?php

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// 기본 필수 필드 확인 (birthday, gender 제외)
$required_fields = ['nickname', 'email', 'password', 'phone_number', 'location'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        sendError("{$field} 필드는 필수입니다");
    }
}

// 변수 할당 (DB 연결 전에 미리)
$nickname     = trim($input['nickname']);
$email        = trim($input['email']);
$password     = $input['password'];
$phone_number = trim($input['phone_number']);
$location     = trim($input['location']);
// 빈 문자열은 null로 취급 — 복원 가입 시 앱이 숨긴 필드를 ""로 보내는데,
// 이대로 UPDATE하면 user_birthday = '' 가 되어 날짜 형식 오류(SQLSTATE 22007)가 남
$birthday     = isset($input['birthday']) && trim($input['birthday']) !== '' ? trim($input['birthday']) : null;
$gender       = isset($input['gender'])   && $input['gender'] !== ''         ? $input['gender']         : null;
$profile_image = isset($input['profile_image']) ? trim($input['profile_image']) : null;

// 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {

    // 복원 여부 먼저 확인 (birthday/gender 검증 전)
    // 기준은 전화번호가 아닌 이메일: 탈퇴 시 원본 이메일이 user_withdrawn_email에 보관되고,
    // 이 이메일은 아래 1번(인증코드 검증)을 통과해야 하므로 본인 소유가 보장된다.
    // (전화번호 기준이면 번호가 바뀐 사용자는 복원이 불가능했음)
    $restore_check_query = "SELECT user_id FROM users
                            WHERE user_withdrawn_email = :email
                            AND user_status = '탈퇴'
                            LIMIT 1";
    $restore_check_stmt = $db->prepare($restore_check_query);
    $restore_check_stmt->bindParam(':email', $email);
    $restore_check_stmt->execute();
    $is_restore = $restore_check_stmt->rowCount() > 0;
    $existing_id = $is_restore ? $restore_check_stmt->fetchColumn() : null;

    // 복원이 아닐 때만 birthday, gender 필수 검증
    if (!$is_restore) {
        if (empty($birthday)) {
            sendError("birthday 필드는 필수입니다");
        }
        if (empty($gender)) {
            sendError("gender 필드는 필수입니다");
        }
    }


    // 1. 이메일 인증 완료 여부 확인
    $verify_query = "SELECT * FROM email_verifications 
                     WHERE email = :email 
                     AND is_verified = TRUE 
                     ORDER BY created_at DESC 
                     LIMIT 1";
    $verify_stmt = $db->prepare($verify_query);
    $verify_stmt->bindParam(':email', $email);
    $verify_stmt->execute();
    
    if ($verify_stmt->rowCount() === 0) {
        sendError('이메일 인증이 완료되지 않았습니다');
    }
    
    // 2. 닉네임 중복 확인
    $check_nick_query = "SELECT user_id FROM users WHERE user_nickname = :nickname";
    $check_nick_stmt = $db->prepare($check_nick_query);
    $check_nick_stmt->bindParam(':nickname', $nickname);
    $check_nick_stmt->execute();
    
    if ($check_nick_stmt->rowCount() > 0) {
        sendError('이미 사용중인 닉네임입니다');
    }
    
    // 3. 이메일 중복 확인
    $check_email_query = "SELECT user_id FROM users WHERE user_email = :email";
    $check_email_stmt = $db->prepare($check_email_query);
    $check_email_stmt->bindParam(':email', $email);
    $check_email_stmt->execute();
    
    if ($check_email_stmt->rowCount() > 0) {
        $existing_email_user = $check_email_stmt->fetch(PDO::FETCH_ASSOC);

        // 이 이메일이 소셜 계정에 연결되어 있는지 확인
        $check_social_query = "SELECT provider FROM social_accounts WHERE user_id = :user_id LIMIT 1";
        $check_social_stmt = $db->prepare($check_social_query);
        $check_social_stmt->bindParam(':user_id', $existing_email_user['user_id'], PDO::PARAM_INT);
        $check_social_stmt->execute();

        if ($check_social_stmt->rowCount() > 0) {
            $social_info = $check_social_stmt->fetch(PDO::FETCH_ASSOC);
            $provider_name = match ($social_info['provider']) {
                'kakao' => '카카오',
                'google' => '구글',
                default => $social_info['provider'],
            };
            sendError("이미 {$provider_name} 계정으로 가입되어 있습니다. {$provider_name} 로그인을 이용해주세요");
        }

        sendError('이미 가입된 이메일입니다');
    }
    
    // 4. 전화번호 중복 확인
    // - 활성/정지 계정 소유 → 차단 (기존과 동일)
    // - 탈퇴 계정 소유 → 새 이메일로의 신규가입 차단, 이전 이메일로의 복원 유도
    //   (탈퇴 번호로 계정을 무한 생성해 매너점수를 초기화하는 악용 방지.
    //    단 복원(is_restore) 중이면 통과 — 새 계정이 생기는 게 아니므로)
    $check_phone_query = "SELECT user_id, user_status FROM users WHERE user_phone_number = :phone";
    $check_phone_stmt = $db->prepare($check_phone_query);
    $check_phone_stmt->bindParam(':phone', $phone_number);
    $check_phone_stmt->execute();
    $phone_owners = $check_phone_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($phone_owners as $phone_owner) {
        if ($phone_owner['user_status'] !== '탈퇴') {
            sendError('이미 사용중인 전화번호입니다');
        }
    }

    if (!$is_restore && count($phone_owners) > 0) {
        sendError('이전에 가입한 이메일이 있습니다. 이전에 사용하던 이메일로 가입해주세요');
    }
    
    // 5. profile_image URL 검증 (있을 경우)
    if ($profile_image !== null && !empty($profile_image)) {
        // S3 URL 형식 검증
        if (!filter_var($profile_image, FILTER_VALIDATE_URL)) {
            sendError('올바른 프로필 이미지 URL이 아닙니다');
        }
        
        // honbabapp-images 버킷 URL인지 확인
        if (strpos($profile_image, 'honbabapp-images') === false) {
            sendError('올바른 프로필 이미지 URL이 아닙니다');
        }
    }
    
    // 6. 비밀번호 해싱
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    

    if ($is_restore) {

        // 복원 키가 이메일이므로 전화번호는 바뀌었을 수 있음 → 요청 값으로 갱신
        // birthday/gender는 요청에 있으면 갱신, 없으면 기존 값 유지
        $update_query = "UPDATE users SET
            user_status = '활성',
            user_deleted_at = NULL ,
            user_withdrawn_email = NULL,
            user_email = :email,
            user_email_verified = 1,
            user_password = :password,
            user_nickname = :nickname,
            user_phone_number = :phone_number,
            user_birthday = COALESCE(:birthday, user_birthday),
            user_gender = COALESCE(:gender, user_gender),
            user_profile_image = :profile_image,
            user_location = :location,
            user_updated_at = NOW()
            WHERE user_id = :existing_id";

        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':password', $hashed_password);
        $update_stmt->bindParam(':nickname', $nickname);
        $update_stmt->bindParam(':email', $email);
        $update_stmt->bindParam(':phone_number', $phone_number);
        $update_stmt->bindParam(':birthday', $birthday);
        $update_stmt->bindParam(':gender', $gender);
        $update_stmt->bindParam(':profile_image', $profile_image);
        $update_stmt->bindParam(':location', $location);
        $update_stmt->bindParam(':existing_id', $existing_id, PDO::PARAM_INT);
        
        if ($update_stmt->execute()) {
            sendSuccess('기존 계정이 활성화 됐습니다', [
                'user_id' => $existing_id,
                'email' => $email,
                'nickname' => $nickname,
                'profile_image' => $profile_image
            ]);
            exit;
        } else {
            sendError('활성화 중 오류가 발생했습니다');
            exit;
        }
    }
    
    // 7. 사용자 등록
    $insert_query = "INSERT INTO users 
                    (user_email, user_email_verified, user_password, user_nickname, 
                     user_phone_number, user_birthday, user_gender, user_location, 
                     user_profile_image) 
                    VALUES 
                    (:email, TRUE, :password, :nickname, 
                     :phone_number, :birthday, :gender, :location, 
                     :profile_image)";
    
    $stmt = $db->prepare($insert_query);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':nickname', $nickname);
    $stmt->bindParam(':phone_number', $phone_number);
    $stmt->bindParam(':birthday', $birthday);
    $stmt->bindParam(':gender', $gender);
    $stmt->bindParam(':location', $location);
    $stmt->bindParam(':profile_image', $profile_image);
    
    if ($stmt->execute()) {
        $user_id = $db->lastInsertId();
        
        sendSuccess('회원가입이 완료되었습니다', [
            'user_id' => $user_id,
            'email' => $email,
            'nickname' => $nickname,
            'profile_image' => $profile_image
        ]);
    } else {
        sendError('회원가입 중 오류가 발생했습니다');
    }
    
} catch(PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}
?>