<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

// .env 로딩 보장 (Database 생성자가 loadEnv()를 실행함)
new Database();

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

// Access Token 만료 시간 (초) - 탈취 시 피해 범위를 줄이기 위해 짧게 유지
const ACCESS_TOKEN_TTL_SECONDS = 60 * 60 * 2; // 2시간

// Refresh Token 만료 시간 (초)
const REFRESH_TOKEN_TTL_SECONDS = 60 * 60 * 24 * 30; // 30일

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
    $expiration_time = $issued_at_time + ACCESS_TOKEN_TTL_SECONDS;

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

/**
 * Refresh Token 생성 (랜덤 opaque 토큰)
 *
 * @return string 64자 hex 문자열 (32바이트 랜덤 값)
 */
function generate_refresh_token() {
    return bin2hex(random_bytes(32));
}

/**
 * Refresh Token을 해시하여 DB에 저장한다.
 * 원본 토큰은 절대 저장하지 않는다 (DB 유출 시 그대로 악용되는 것 방지).
 *
 * @param PDO $database 활성화된 DB 커넥션
 * @param int $user_id 토큰 소유자
 * @param string $refresh_token 평문 refresh token (클라이언트에 전달할 값)
 */
function store_refresh_token($database, $user_id, $refresh_token) {
    $token_hash = hash('sha256', $refresh_token);
    $expires_at = date('Y-m-d H:i:s', time() + REFRESH_TOKEN_TTL_SECONDS);

    $insert_refresh_token_statement = $database->prepare("
        INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
        VALUES (:user_id, :token_hash, :expires_at)
    ");
    $insert_refresh_token_statement->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $insert_refresh_token_statement->bindParam(':token_hash', $token_hash);
    $insert_refresh_token_statement->bindParam(':expires_at', $expires_at);
    $insert_refresh_token_statement->execute();
}

/**
 * 로그인/소셜로그인/refresh 성공 시 access token + refresh token을
 * 한 번에 발급하고, refresh token은 DB 저장까지 완료한다.
 *
 * @param PDO $database 활성화된 DB 커넥션
 * @param int $user_id 토큰을 발급받을 사용자
 * @return array ['access_token' => string, 'refresh_token' => string]
 */
function issue_token_pair($database, $user_id) {
    $access_token = generate_jwt($user_id);
    $refresh_token = generate_refresh_token();
    store_refresh_token($database, $user_id, $refresh_token);

    return [
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
    ];
}
?>