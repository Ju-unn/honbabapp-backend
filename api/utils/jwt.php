<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

// .env 로딩 보장 (Database 생성자가 loadEnv()를 실행함)
new Database();

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

/**
 * JWT 토큰 발급 함수
 * 로그인/회원가입/소셜로그인 성공 시 호출
 *
 * @param int $user_id 토큰에 담을 사용자 고유번호
 * @return string 발급된 JWT 문자열
 */
function generate_jwt($user_id) {
    $secret_key = getenv('JWT_SECRET');

    $issued_at_time = time();
    $expiration_time = $issued_at_time + (60 * 60 * 24 * 14); // 발급 후 14일 동안 유효

    $payload = [
        'iat' => $issued_at_time,      // 토큰 발급 시각
        'exp' => $expiration_time,     // 토큰 만료 시각
        'user_id' => $user_id          // 실제로 우리가 필요한 정보
    ];

    return JWT::encode($payload, $secret_key, 'HS256');
}

/**
 * 요청 헤더에서 JWT 토큰을 꺼내 검증하고 user_id를 반환
 * 토큰이 없거나, 만료됐거나, 위조됐으면 401 에러로 즉시 응답하고 종료
 *
 * 사용 예시 (민감 API 상단에 추가):
 *   require_once '../utils/jwt.php';
 *   $user_id = get_user_id_from_token();
 *
 * @return int 검증된 사용자 고유번호
 */
function get_user_id_from_token() {
    $secret_key = getenv('JWT_SECRET');

    // 1. 헤더에서 Authorization 값 꺼내기
    $auth_header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $auth_header = $headers['Authorization'];
        }
    }

    // 2. "Bearer 토큰값" 형식인지 확인하고 토큰값만 추출
    if (empty($auth_header) || !preg_match('/Bearer\s+(.+)/', $auth_header, $matches)) {
        sendError('로그인이 필요합니다 (토큰 없음)', 401);
        exit;
    }

    $jwt_token = $matches[1];

    // 3. 토큰 검증
    try {
        $decoded = JWT::decode($jwt_token, new Key($secret_key, 'HS256'));
        return (int)$decoded->user_id;
    } catch (ExpiredException $e) {
        sendError('로그인이 만료되었습니다. 다시 로그인해주세요', 401);
        exit;
    } catch (Exception $e) {
        sendError('유효하지 않은 인증 정보입니다', 401);
        exit;
    }
}
?>