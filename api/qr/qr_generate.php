<?php
/**
 * QR 토큰 생성 API (호스트 전용)
 * POST /api/meeting/qr_generate.php
 *
 * 요청:
 * {
 *   "meeting_id": 1,
 *   "user_id": 3
 * }
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "QR이 생성되었습니다",
 *   "data": {
 *     "code_token": "a3f8c2d1...(64자)",
 *     "expires_at": "2025-12-26 19:00:00",
 *     "meeting_id": 1,
 *     "is_newly_generated": true
 *   }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// 필수 파라미터 확인
if (empty($input['meeting_id']) || empty($input['user_id'])) {
    sendError('meeting_id와 user_id는 필수입니다');
}

$meeting_id = intval($input['meeting_id']);
$request_user_id = intval($input['user_id']);

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // ─────────────────────────────────────────────
    // 1. 모임 정보 조회
    //    meeting_host_id : 호스트 검증용
    //    meeting_date    : 시간 창(24h 전 ~ meeting_date+24h) 계산용
    // ─────────────────────────────────────────────
    $meeting_query = "SELECT meeting_id, meeting_host_id, meeting_date
                      FROM meetings
                      WHERE meeting_id = :meeting_id";

    $meeting_stmt = $db->prepare($meeting_query);
    $meeting_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $meeting_stmt->execute();

    if ($meeting_stmt->rowCount() === 0) {
        sendError('존재하지 않는 모임입니다', 404);
    }

    $meeting = $meeting_stmt->fetch(PDO::FETCH_ASSOC);

    // ─────────────────────────────────────────────
    // 2. 호스트 권한 확인
    //    요청한 user_id가 이 모임의 호스트가 아니면 거부
    // ─────────────────────────────────────────────
    if ($meeting['meeting_host_id'] != $request_user_id) {
        sendError('호스트만 QR을 생성할 수 있습니다', 403);
    }

    // ─────────────────────────────────────────────
    // 3. 시간 창 검사
    //
    //    생성 가능 구간: (meeting_date - 24h) ~ (meeting_date + 24h)
    //
    //    예) 모임이 12월 25일 19:00 이라면
    //        → 12월 24일 19:00 부터 생성 가능
    //        → 12월 26일 19:00 이후면 이미 만료
    // ─────────────────────────────────────────────
    $meeting_timestamp  = strtotime($meeting['meeting_date']);
    $window_open_timestamp  = $meeting_timestamp - (24 * 60 * 60); // meeting_date - 24시간
    $window_close_timestamp = $meeting_timestamp + (24 * 60 * 60); // meeting_date + 24시간
    $current_timestamp  = time();

    if ($current_timestamp < $window_open_timestamp) {
        // 아직 24시간 전이 안 됨
        $window_open_formatted = date('Y-m-d H:i', $window_open_timestamp);
        sendError("QR은 모임 24시간 전부터 생성할 수 있습니다. (생성 가능 시각: {$window_open_formatted})");
    }

    if ($current_timestamp > $window_close_timestamp) {
        // 모임이 이미 끝났고 만료 시각도 지남
        sendError('이미 종료된 모임입니다. QR을 생성할 수 없습니다.');
    }

    // ─────────────────────────────────────────────
    // 4. 이미 생성된 QR이 있는지 확인
    //    UNIQUE KEY uq_meeting(meeting_id) 덕분에
    //    같은 모임에 QR은 최대 1개만 존재한다.
    //    이미 있으면 새로 만들지 않고 기존 토큰을 그대로 반환 (idempotent)
    // ─────────────────────────────────────────────
    $existing_qr_query = "SELECT code_token, expires_at
                          FROM meeting_qr_codes
                          WHERE meeting_id = :meeting_id";

    $existing_qr_stmt = $db->prepare($existing_qr_query);
    $existing_qr_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $existing_qr_stmt->execute();

    if ($existing_qr_stmt->rowCount() > 0) {
        // 이미 존재하는 QR → 기존 토큰 반환
        $existing_qr = $existing_qr_stmt->fetch(PDO::FETCH_ASSOC);
        sendSuccess('이미 생성된 QR이 있습니다', [
            'code_token'         => $existing_qr['code_token'],
            'expires_at'         => $existing_qr['expires_at'],
            'meeting_id'         => $meeting_id,
            'is_newly_generated' => false   // 새로 만든 게 아님을 프론트에 알림
        ]);
    }

    // ─────────────────────────────────────────────
    // 5. 신규 QR 토큰 생성
    //    bin2hex(random_bytes(32)) → 64자리 16진수 문자열
    //    예: "3f8a2c..." (매번 완전히 다른 값, 예측 불가)
    //
    //    expires_at = meeting_date + 24시간
    // ─────────────────────────────────────────────
    $code_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', $window_close_timestamp);

    $insert_qr_query = "INSERT INTO meeting_qr_codes
                            (meeting_id, code_token, expires_at)
                        VALUES
                            (:meeting_id, :code_token, :expires_at)";

    $insert_qr_stmt = $db->prepare($insert_qr_query);
    $insert_qr_stmt->bindParam(':meeting_id', $meeting_id,  PDO::PARAM_INT);
    $insert_qr_stmt->bindParam(':code_token', $code_token,  PDO::PARAM_STR);
    $insert_qr_stmt->bindParam(':expires_at', $expires_at,  PDO::PARAM_STR);
    $insert_qr_stmt->execute();

    sendSuccess('QR이 생성되었습니다', [
        'code_token'         => $code_token,
        'expires_at'         => $expires_at,
        'meeting_id'         => $meeting_id,
        'is_newly_generated' => true    // 방금 새로 만든 QR
    ]);

} catch (PDOException $error) {
    sendError('QR 생성 중 오류가 발생했습니다: ' . $error->getMessage(), 500);
}
?>