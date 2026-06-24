<?php

/**
 * 카카오 전화번호(+82 010-1234-5678)를 우리 DB 형식(010-1234-5678)으로 변환
 *
 * @param string|null $kakao_phone 카카오가 내려준 전화번호
 * @return string|null 변환된 전화번호, 변환 불가능하면 null
 */
function normalize_kakao_phone($kakao_phone) {
    if ($kakao_phone === null) {
        return null;
    }

    // "+82 010-1234-5678" → 숫자만 추출 → "82010xxxxxxxx"
    $digits_only = preg_replace('/\D/', '', $kakao_phone);

    // 국가코드(82)로 시작하면 제거하고 앞에 0을 붙임
    if (str_starts_with($digits_only, '82')) {
        $digits_only = '0' . substr($digits_only, 2);
    }

    // 010xxxxxxxx (11자리, 010으로 시작)인지 확인
    if (strlen($digits_only) !== 11 || !str_starts_with($digits_only, '010')) {
        // 우리 서비스가 지원하는 형식(010-XXXX-XXXX)이 아니면 통합에 사용하지 않음
        return null;
    }

    // 010-1234-5678 형식으로 재조합
    return substr($digits_only, 0, 3) . '-' . substr($digits_only, 3, 4) . '-' . substr($digits_only, 7, 4);
}

/**
 * 카카오 access_token으로 사용자 정보 조회
 *
 * 공식 문서: https://developers.kakao.com/docs/ko/kakaologin/rest-api#req-user-info
 *
 * @param string $access_token 안드로이드 앱이 카카오 SDK로 로그인 후 받은 토큰
 * @return array|null 성공 시 사용자 정보 배열, 실패 시 null
 */
function get_kakao_user_info($access_token) {
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://kapi.kakao.com/v2/user/me',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/x-www-form-urlencoded;charset=utf-8'
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);

    if ($curl_error) {
        error_log('카카오 API 통신 오류: ' . $curl_error);
        return null;
    }

    if ($http_code !== 200) {
        error_log('카카오 API 응답 오류 (HTTP ' . $http_code . '): ' . $response);
        return null;
    }

    $kakao_data = json_decode($response, true);

    if ($kakao_data === null || !isset($kakao_data['id'])) {
        error_log('카카오 API 응답 파싱 실패: ' . $response);
        return null;
    }

    $kakao_account = $kakao_data['kakao_account'] ?? [];
    $profile = $kakao_account['profile'] ?? [];

    // 이메일 신뢰성 검증 (다른 계정에서 만료되지 않았고, 실제로 인증된 이메일인지)
    $email = null;
    $is_email_valid = $kakao_account['is_email_valid'] ?? false;
    $is_email_verified = $kakao_account['is_email_verified'] ?? false;

    if ($is_email_valid && $is_email_verified && isset($kakao_account['email'])) {
        $email = $kakao_account['email'];
    }

    // 전화번호 추출 + 우리 DB 형식으로 변환
    $phone_number = null;
    if (isset($kakao_account['phone_number'])) {
        $phone_number = normalize_kakao_phone($kakao_account['phone_number']);
    }

    return [
        'provider_user_id' => (string)$kakao_data['id'],
        'email' => $email,
        'phone_number' => $phone_number,
        'nickname' => $profile['nickname'] ?? null,
        'profile_image' => $profile['profile_image_url'] ?? null,
    ];
}

/**
 * 구글 id_token 검증 및 사용자 정보 조회
 *
 * 공식 문서: https://developers.google.com/identity/sign-in/web/backend-auth
 *
 * @param string $id_token 안드로이드 앱이 구글 Credential Manager로 로그인 후 받은 id_token
 * @return array|null 성공 시 사용자 정보 배열, 실패 시 null
 */
function get_google_user_info($id_token) {
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);

    if ($curl_error) {
        error_log('구글 API 통신 오류: ' . $curl_error);
        return null;
    }

    // 구글은 토큰이 유효하지 않으면 200이 아닌 응답(보통 400)을 줌
    if ($http_code !== 200) {
        error_log('구글 토큰 검증 실패 (HTTP ' . $http_code . '): ' . $response);
        return null;
    }

    $google_data = json_decode($response, true);

    if ($google_data === null || !isset($google_data['sub'])) {
        error_log('구글 API 응답 파싱 실패: ' . $response);
        return null;
    }

    // 보안 검증: aud가 우리 웹 클라이언트 ID와 일치하는지 확인
    // 이게 없으면 다른 앱용으로 발급된 구글 토큰도 통과되어버림 (위조 토큰 위험)
    $expected_client_id = getenv('GOOGLE_WEB_CLIENT_ID');
    if (!isset($google_data['aud']) || $google_data['aud'] !== $expected_client_id) {
        error_log('구글 토큰 aud 불일치. 받은 값: ' . ($google_data['aud'] ?? 'null'));
        return null;
    }

    // 이메일 검증 여부 확인 (구글은 email_verified를 문자열 "true"/"false"로 줄 때가 있어 느슨하게 비교)
    $email_verified = isset($google_data['email_verified'])
        && ($google_data['email_verified'] === true || $google_data['email_verified'] === 'true');

    $email = null;
    if ($email_verified && isset($google_data['email'])) {
        $email = $google_data['email'];
    }

    return [
        'provider_user_id' => $google_data['sub'],
        'email' => $email,
        'phone_number' => null, // 구글은 id_token에 전화번호를 포함하지 않음
        'nickname' => $google_data['name'] ?? null,
        'profile_image' => $google_data['picture'] ?? null,
    ];
}

?>