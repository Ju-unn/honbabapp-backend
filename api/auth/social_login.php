<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';
require_once 'social_providers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// 1. 입력 검증
// 구글은 id_token, 카카오는 access_token을 사용하므로 둘 다 허용
if (empty($input['provider'])) {
    sendError('provider가 필요합니다');
}

$provider = trim($input['provider']);
$credential = trim($input['access_token'] ?? $input['id_token'] ?? '');

if (empty($credential)) {
    sendError('access_token 또는 id_token이 필요합니다');
}

$allowed_providers = ['kakao', 'google'];
if (!in_array($provider, $allowed_providers)) {
    sendError('지원하지 않는 provider입니다');
}

// 2. DB 연결 (먼저 연결해야 .env의 환경변수들이 로딩됨)
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

// 3. provider별 사용자 정보 조회
$social_user_info = null;
if ($provider === 'kakao') {
    $social_user_info = get_kakao_user_info($credential);
} elseif ($provider === 'google') {
    $social_user_info = get_google_user_info($credential);
}

if ($social_user_info === null) {
    sendError('소셜 로그인 인증에 실패했습니다. 토큰을 확인해주세요', 401);
}

$provider_user_id = $social_user_info['provider_user_id'];
$verified_email = $social_user_info['email'];
$verified_phone = $social_user_info['phone_number'];
$social_nickname = $social_user_info['nickname'];
$social_profile_image = $social_user_info['profile_image'];

try {
    $db->beginTransaction();

    $user_id = null;
    $is_new_user = false;

    // ───────────────────────────────────────────────
    // 시나리오 1: 이미 연결된 소셜 계정인지 확인 (재로그인)
    // ───────────────────────────────────────────────
    $check_social_query = "SELECT user_id FROM social_accounts 
                            WHERE provider = :provider AND provider_user_id = :provider_user_id";
    $check_social_stmt = $db->prepare($check_social_query);
    $check_social_stmt->bindParam(':provider', $provider);
    $check_social_stmt->bindParam(':provider_user_id', $provider_user_id);
    $check_social_stmt->execute();

    if ($check_social_stmt->rowCount() > 0) {
        // 기존 회원 - 바로 로그인
        $existing_social = $check_social_stmt->fetch(PDO::FETCH_ASSOC);
        $user_id = $existing_social['user_id'];
    } else {
        // ───────────────────────────────────────────────
        // 시나리오 2: 검증된 이메일로 기존 users 조회 (계정 통합)
        // ───────────────────────────────────────────────
        if ($verified_email !== null) {
            $check_email_query = "SELECT user_id FROM users WHERE user_email = :email";
            $check_email_stmt = $db->prepare($check_email_query);
            $check_email_stmt->bindParam(':email', $verified_email);
            $check_email_stmt->execute();

            if ($check_email_stmt->rowCount() > 0) {
                $existing_user = $check_email_stmt->fetch(PDO::FETCH_ASSOC);
                $user_id = $existing_user['user_id'];
            }
        }

        // ───────────────────────────────────────────────
        // 시나리오 3: 검증된 전화번호로 기존 users 조회 (계정 통합)
        // ───────────────────────────────────────────────
        if ($user_id === null && $verified_phone !== null) {
            $check_phone_query = "SELECT user_id FROM users WHERE user_phone_number = :phone";
            $check_phone_stmt = $db->prepare($check_phone_query);
            $check_phone_stmt->bindParam(':phone', $verified_phone);
            $check_phone_stmt->execute();

            if ($check_phone_stmt->rowCount() > 0) {
                $existing_user = $check_phone_stmt->fetch(PDO::FETCH_ASSOC);
                $user_id = $existing_user['user_id'];
            }
        }

        // ───────────────────────────────────────────────
        // 시나리오 4: 일치하는 계정이 없으면 완전 신규 가입
        // ───────────────────────────────────────────────
        if ($user_id === null) {
            $is_new_user = true;

            // 소셜에서 받은 닉네임이 이미 사용 중이면 null로 처리
            // (온보딩 화면에서 사용자가 직접 고유한 닉네임을 입력하게 됨)
            $final_nickname = null;
            if ($social_nickname !== null) {
                $check_nickname_query = "SELECT user_id FROM users WHERE user_nickname = :nickname";
                $check_nickname_stmt = $db->prepare($check_nickname_query);
                $check_nickname_stmt->bindParam(':nickname', $social_nickname);
                $check_nickname_stmt->execute();

                if ($check_nickname_stmt->rowCount() === 0) {
                    // 중복 없음 - 소셜 닉네임 그대로 사용
                    $final_nickname = $social_nickname;
                }
                // 중복 있으면 $final_nickname은 null로 유지 (온보딩에서 입력받음)
            }

            // 비밀번호가 NOT NULL이므로, 아무도 모르는 임의의 해시값을 채워넣음
            $random_password_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

            $insert_user_query = "INSERT INTO users 
                                   (user_email, user_email_verified, user_password, user_nickname, 
                                    user_profile_image, user_phone_number, user_status) 
                                   VALUES 
                                   (:email, :email_verified, :password, :nickname, 
                                    :profile_image, :phone_number, '활성')";
            $insert_user_stmt = $db->prepare($insert_user_query);
            $insert_user_stmt->bindParam(':email', $verified_email);
            $email_verified_flag = $verified_email !== null ? 1 : 0;
            $insert_user_stmt->bindParam(':email_verified', $email_verified_flag);
            $insert_user_stmt->bindParam(':password', $random_password_hash);
            $insert_user_stmt->bindParam(':nickname', $final_nickname);
            $insert_user_stmt->bindParam(':profile_image', $social_profile_image);
            $insert_user_stmt->bindParam(':phone_number', $verified_phone);
            $insert_user_stmt->execute();

            $user_id = $db->lastInsertId();
        }

        // social_accounts에 연결 정보 저장 (신규가입이든 기존계정 연결이든 공통)
        $insert_social_query = "INSERT INTO social_accounts 
                                 (user_id, provider, provider_user_id, provider_email) 
                                 VALUES 
                                 (:user_id, :provider, :provider_user_id, :provider_email)";
        $insert_social_stmt = $db->prepare($insert_social_query);
        $insert_social_stmt->bindParam(':user_id', $user_id);
        $insert_social_stmt->bindParam(':provider', $provider);
        $insert_social_stmt->bindParam(':provider_user_id', $provider_user_id);
        $insert_social_stmt->bindParam(':provider_email', $verified_email);
        $insert_social_stmt->execute();
    }

    // 4. 최종 사용자 정보 조회
    $user_query = "SELECT user_id, user_email, user_nickname, user_profile_image, 
                          user_gender, user_birthday, user_location, user_phone_number,
                          user_manner_score, user_participation_count, user_status,
                          user_email_verified
                   FROM users WHERE user_id = :user_id";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bindParam(':user_id', $user_id);
    $user_stmt->execute();
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    $user['user_email_verified'] = (bool)$user['user_email_verified'];

    // 계정 상태 확인
    // '탈퇴'는 즉시 복원: provider가 본인을 보증하므로 이메일 인증 절차가 불필요하고,
    // 에러로 막으면 소셜 사용자는 복원 경로가 아예 없음 (register.php의 이메일 복원과 대칭)
    if ($user['user_status'] === '탈퇴') {
        // 소셜에서 검증된 이메일이 다른 계정에서 사용 중이 아니면 함께 복구
        $restore_email = null;
        if ($verified_email !== null) {
            $email_owner_stmt = $db->prepare(
                "SELECT user_id FROM users WHERE user_email = :email AND user_id != :user_id LIMIT 1");
            $email_owner_stmt->bindParam(':email', $verified_email);
            $email_owner_stmt->bindParam(':user_id', $user['user_id'], PDO::PARAM_INT);
            $email_owner_stmt->execute();
            if ($email_owner_stmt->rowCount() === 0) {
                $restore_email = $verified_email;
            }
        }

        $restore_query = "UPDATE users SET
                              user_status = '활성',
                              user_deleted_at = NULL,
                              user_withdrawn_email = NULL,
                              user_email = COALESCE(:email, user_email),
                              user_email_verified = CASE WHEN :email2 IS NULL
                                                         THEN user_email_verified ELSE 1 END,
                              user_updated_at = NOW()
                          WHERE user_id = :user_id";
        $restore_stmt = $db->prepare($restore_query);
        $restore_stmt->bindParam(':email', $restore_email);
        $restore_stmt->bindParam(':email2', $restore_email);
        $restore_stmt->bindParam(':user_id', $user['user_id'], PDO::PARAM_INT);
        $restore_stmt->execute();

        // 복원된 정보로 다시 조회 (닉네임이 NULL이므로 아래 온보딩 분기가 자동으로 처리됨)
        $user_stmt->execute();
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        $user['user_email_verified'] = (bool)$user['user_email_verified'];
    }

    if ($user['user_status'] !== '활성') {
        $db->rollBack();
        $msg = match ($user['user_status']) {
            '정지' => '정지된 계정입니다',
            default => '접근이 제한된 계정입니다',
        };
        sendError($msg);
    }

    $db->commit();

    // 5. 온보딩 필요 여부 판단 — check_onboarding_status.php와 동일한 5개 필드 기준
    // (하나라도 비어있으면 온보딩 필요. 앱이 비어있는 필드만 골라서 입력받음)
    $needs_onboarding = empty($user['user_nickname'])
        || empty($user['user_location'])
        || empty($user['user_phone_number'])
        || empty($user['user_gender'])
        || empty($user['user_birthday']);

      // 6. Access + Refresh 토큰 쌍 발급
      $token_pair = issue_token_pair($db, $user['user_id']);
      $user['token'] = $token_pair['access_token'];
      $user['refresh_token'] = $token_pair['refresh_token'];

    $message = $is_new_user ? '소셜 회원가입이 완료되었습니다' : '소셜 로그인 성공';

    sendSuccess($message, [
        'user' => $user,
        'needs_onboarding' => $needs_onboarding
    ]);

} catch (PDOException $e) {
    // commit() 이후(토큰 발급 단계)의 예외면 트랜잭션이 없으므로 무조건 rollBack() 시 2차 예외 발생
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}

?>