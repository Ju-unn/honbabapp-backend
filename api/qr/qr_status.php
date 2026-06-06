<?php
/**
 * QR 버튼 상태 확인 API
 * GET /api/meeting/qr_status.php
 *
 * 채팅방 진입 시 호출 → 프론트가 버튼 동작/문구를 결정하는 데 사용
 *
 * 요청:
 * GET /api/meeting/qr_status.php?meeting_id=1&user_id=3
 *
 * 응답:
 * {
 *   "success": true,
 *   "message": "QR 상태 조회 성공",
 *   "data": {
 *     "is_host": true,
 *     "state": "generated",      // before_window | available | generated | expired
 *     "code_token": "a3f8...",   // 호스트 & state=generated 일 때만 포함
 *     "expires_at": "2025-12-26 19:00:00"
 *   }
 * }
 *
 * state 설명:
 *   before_window : 모임 24시간 전이 아직 안 됨 → "아직 생성할 수 없습니다" 안내
 *   available     : 호스트, 창 안, 미생성 → 버튼 누르면 qr_generate.php 호출
 *   generated     : 이미 QR 있음 → 바로 QR 이미지 표시
 *   expired       : meeting_date + 24h 경과 → "종료된 모임" 안내
 */

 
 
require_once '../config/database.php';
require_once '../utils/response.php';
 
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}
 
// 필수 파라미터 확인
if (empty($_GET['meeting_id']) || empty($_GET['user_id'])) {
    sendError('meeting_id와 user_id는 필수입니다');
}
 
$meeting_id      = intval($_GET['meeting_id']);
$request_user_id = intval($_GET['user_id']);
 
// DB 연결
$database = new Database();
$db = $database->getConnection();
 
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}
 

try {
  // 1. 모임 기본 정보 조회

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
    // 2. 호스트 여부 판단
    // ─────────────────────────────────────────────
    $is_host = ($meeting['meeting_host_id'] == $request_user_id);
 
    // ─────────────────────────────────────────────
    // 3. 시간 창 계산
    //    window_open  = meeting_date - 24h (QR 생성 가능 시작)
    //    window_close = meeting_date + 24h (QR 만료)
    // ─────────────────────────────────────────────
    $meeting_timestamp   = strtotime($meeting['meeting_date']);
    $window_open_timestamp   = $meeting_timestamp - (24 * 60 * 60);
    $window_close_timestamp  = $meeting_timestamp + (24 * 60 * 60);
    $current_timestamp   = time();
 
    // ─────────────────────────────────────────────
    // 4. 이미 생성된 QR 조회
    // ─────────────────────────────────────────────
    $qr_query = "SELECT code_token, expires_at
                 FROM meeting_qr_codes
                 WHERE meeting_id = :meeting_id";
 
    $qr_stmt = $db->prepare($qr_query);
    $qr_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $qr_stmt->execute();
 
    $existing_qr = ($qr_stmt->rowCount() > 0) ? $qr_stmt->fetch(PDO::FETCH_ASSOC) : null;

    // 5. state 결정 로직
    // 만료시간 지남 
    // qr 이미 존재
    // 아직 창이 안 열림
    // 그 외

    if ($current_timestamp > $window_close_timestamp) {
      $state = 'expired';
    } elseif ($existing_qr !== null) {
        $state = 'generated';
    } elseif ($current_timestamp < $window_open_timestamp) {
        $state = 'before_window';
    } else {
        $state = 'available';
    }

    // 6. 응답 데이터 구성
    // code_token은 호스트 + state=generated 일 떄만 포함
    // 참여자에게 토큰을 미리 노출하면 위치 검증 우회 가능

    $response_data = [
      'is_host'    => $is_host,
      'state'      => $state,
      'expires_at' => $existing_qr ? $existing_qr['expires_at'] : date('Y-m-d H:i:s', $window_close_timestamp),
  ];

  if ($is_host && $state === 'generated') {
    $response_data['code_token'] = $existing_qr['code_token'];
}

sendSuccess('QR 상태 조회 성공', $response_data);

} catch (PDOException $error) {
sendError('QR 상태 조회 중 오류가 발생했습니다: ' . $error->getMessage(), 500);
}
?>