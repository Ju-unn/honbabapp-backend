<?php
/**
 * 온보딩(추가정보 입력) 완료 여부 확인 API
 *
 * 소셜 로그인 자동 로그인 시, 닉네임/위치/성별/생일/전화번호 중
 * 하나라도 비어있으면 온보딩 화면으로 다시 보내기 위해 사용.
 *
 * GET /api/user/check_onboarding_status.php?user_id=1
 *
 * 응답:
 * {
 *   "success": true,
 *   "data": {
 *     "needs_onboarding": true,
 *     "missing": {
 *       "nickname": false,
 *       "phone_number": true,
 *       "location": true,
 *       "gender": false,
 *       "birthday": false
 *     }
 *   }
 * }
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
     sendError('GET 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (쿼리스트링의 user_id를 신뢰하지 않음 — 위조 방지)
 $user_id = get_user_id_from_token();

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $query = "SELECT user_nickname, user_phone_number, user_location, user_gender, user_birthday
              FROM users WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendError('존재하지 않는 사용자입니다', 404);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $missing = [
        'nickname'     => empty($user['user_nickname']),
        'phone_number' => empty($user['user_phone_number']),
        'location'     => empty($user['user_location']),
        'gender'       => empty($user['user_gender']),
        'birthday'     => empty($user['user_birthday']),
    ];

    $needs_onboarding = in_array(true, $missing, true);

    sendSuccess('온보딩 상태 조회 성공', [
        'needs_onboarding' => $needs_onboarding,
        'missing' => $missing,
    ]);

} catch (PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}

?>