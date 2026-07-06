<?php
/**
 * QR 스캔 인증 API
 * POST /api/meeting/qr_scan.php
 *
 * 요청:
 * {
 *   "code_token": "a3f8c2d1...(64자)",
 *   "user_id": 5,
 *   "lat": 37.4979,
 *   "lng": 127.0276
 * }
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "QR 스캔이 완료되었습니다",
 *   "data": {
 *     "participation_count": 7
 *   }
 * }
 *
 * 검증 순서:
 *   1. 토큰 유효성 (존재 여부)
 *   2. 만료 검사 (expires_at)
 *   3. 당일 검사 (meeting_date 날짜 == 오늘 날짜)
 *   4. 호스트 본인 스캔 차단
 *   5. 승인 참여자 여부
 *   6. 거리 검사 (500m 이내)
 *   7. 중복 스캔 방지 + 카운트 증가 (트랜잭션)
 */
 

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
  
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     sendError('POST 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
 $scanner_user_id = get_user_id_from_token();
  
 $input = json_decode(file_get_contents('php://input'), true);
  
 // 필수 파라미터 확인
 if (empty($input['code_token'])) {
     sendError('code_token은 필수입니다');
 }
 if (!isset($input['lat']) || !isset($input['lng'])) {
     sendError('현재 위치 필수입니다');
 }
 
 $code_token  = trim($input['code_token']);
 $scanner_lat = floatval($input['lat']);
 $scanner_lng = floatval($input['lng']);


// DB 연결
$database = new Database();
$db = $database->getConnection();
 
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
  // 1. 토큰으로 qr + 모임 + 장소 정보 한 번에 조회
  // map_x = 경도(longitude) , map_y = 위도(latitude)

  $qr_info_query = "SELECT
                      qr.qr_id,
                      qr.expires_at,
                      qr.host_credited,
                      m.meeting_id,
                      m.meeting_host_id,
                      m.meeting_date,
                      l.map_x,
                      l.map_y
                      FROM meeting_qr_codes qr
                      JOIN meetings  m ON qr.meeting_id  = m.meeting_id
                      JOIN locations l ON m.location_id  = l.location_id
                      WHERE qr.code_token = :code_token";
  
  $qr_info_stmt = $db->prepare($qr_info_query);
  $qr_info_stmt->bindParam(':code_token', $code_token, PDO::PARAM_STR);
  $qr_info_stmt->execute();

  if ($qr_info_stmt->rowCount() === 0) {
    sendError('유효하지 않은 QR입니다');
  }

  $qr_info = $qr_info_stmt->fetch(PDO::FETCH_ASSOC);

  // 2. 만료 검사 (자연 만료 처리)

  if (time() > strtotime($qr_info['expires_at'])) {
    sendError('만료된 QR 입니다.');
  }

  // ─────────────────────────────────────────────
    // 3. 당일 검사
    //    meeting_date의 날짜(연-월-일)와 오늘 날짜가 같아야 함
    //    예) meeting_date = "2025-12-25 19:00:00"
    //        → DATE(...) = "2025-12-25"
    //        오늘이 12-25 이어야 스캔 허용
    // ─────────────────────────────────────────────
    $meeting_date_only = date('Y-m-d', strtotime($qr_info['meeting_date']));
    $today_date_only   = date('Y-m-d');
 
    if ($meeting_date_only !== $today_date_only) {
        sendError('모임 당일에만 출석 인증을 할 수 있습니다');
    }

    // ─────────────────────────────────────────────
    // 4. 호스트 본인 스캔 차단
    //    호스트는 첫 번째 참여자가 스캔할 때 자동으로 +1 되므로
    //    본인이 직접 스캔하는 건 의미 없고 허용하지 않음
    // ─────────────────────────────────────────────
    if ($qr_info['meeting_host_id'] == $scanner_user_id) {
      sendError('호스트는 직접 스캔할 수 없습니다.');
    }

     // ─────────────────────────────────────────────
    // 5. 승인된 참여자인지 확인
    //    meeting_participants에서 이 유저가 '승인' 상태인지 체크
    // ─────────────────────────────────────────────
    $participant_check_query = "SELECT participant_id
                                FROM meeting_participants
                                WHERE meeting_id = :meeting_id
                                  AND user_id    = :user_id
                                  AND participation_status = '승인'";
 
    $participant_check_stmt = $db->prepare($participant_check_query);
    $participant_check_stmt->bindParam(':meeting_id', $qr_info['meeting_id'], PDO::PARAM_INT);
    $participant_check_stmt->bindParam(':user_id',    $scanner_user_id,       PDO::PARAM_INT);
    $participant_check_stmt->execute();
 
    if ($participant_check_stmt->rowCount() === 0) {
        sendError('승인된 참여자만 출석 인증을 할 수 있습니다');
    }

    // ─────────────────────────────────────────────
    // 6. 거리 검사 (500m 이내)
    //
    //    map_x = 경도(longitude), map_y = 위도(latitude)
    //    Haversine 공식: 지구 표면의 두 점 사이 거리(km) 계산
    //    list.php에서 사용 중인 공식과 동일하게 PHP로 구현
    //
    //    모임 장소 좌표가 없으면 거리 검사 불가 → 인증 거부
    // ─────────────────────────────────────────────
    if (empty($qr_info['map_x']) || empty($qr_info['map_y'])) {
      sendError('모임 위치 정보가 없어 출석 인증을 할 수 없습니다');
  }

  $meeting_lat = floatval($qr_info['map_y']); // map_y = 위도
  $meeting_lng = floatval($qr_info['map_x']); // map_x = 경도

  // Haversine 공식 
  $earth_radius_km = 6371;
  $lat_diff_rad = deg2rad($scanner_lat - $meeting_lat);
  $lng_diff_rad = deg2rad($scanner_lng - $meeting_lng);

  $haversine_a = sin($lat_diff_rad / 2) * sin($lat_diff_rad / 2)
               + cos(deg2rad($meeting_lat)) * cos(deg2rad($scanner_lat))
               * sin($lng_diff_rad / 2) * sin($lng_diff_rad / 2);

  $haversine_c    = 2 * atan2(sqrt($haversine_a), sqrt(1 - $haversine_a));
  $distance_km    = $earth_radius_km * $haversine_c;

  $max_distance_km = 0.5; // 500m

  if ($distance_km > $max_distance_km) {
      $distance_meters = round($distance_km * 1000);
      sendError("만남 장소 500m 이내에서만 인증할 수 있습니다. (현재 거리: 약 {$distance_meters}m)");
  }

  // 7. 중복방지 + 카운터 증가 
  //    순서:
  //    (a) meeting_qr_scans INSERT
  //        → UNIQUE(qr_id, user_id) 위반 시 이미 스캔한 것
  //    (b) 스캔한 유저 user_participation_count +1
  //    (c) 이 QR의 첫 번째 스캔이면 호스트도 +1
  //        → host_credited 0→1 UPDATE의 affected rows로 판단
  //           (동시에 두 명이 스캔해도 한 번만 호스트 +1 됨)
  $db->beginTransaction();

  // 스캔 기록 INSERT
  $scan_insert_query = "INSERT INTO meeting_qr_scans (qr_id , user_id)
                        VALUES (:qr_id , :user_id)";

  $scan_insert_stmt = $db->prepare($scan_insert_query);
  $scan_insert_stmt->bindParam(':qr_id',   $qr_info['qr_id'], PDO::PARAM_INT);
  $scan_insert_stmt->bindParam(':user_id', $scanner_user_id,  PDO::PARAM_INT);                      

  try {
        $scan_insert_stmt->execute();
    } catch (PDOException $duplicate_error) {
        // SQLSTATE 23000 = UNIQUE 제약 위반 (이미 스캔한 유저)
        $db->rollBack();
        if ($duplicate_error->getCode() === '23000') {
            sendError('이미 출석 인증을 완료했습니다');
        }
        throw $duplicate_error; // 다른 오류면 다시 던져서 바깥 catch에서 처리
    }
 
  // (b) 스캔한 참여자 participation_count +1
    $scanner_count_update_query = "UPDATE users
                                   SET user_participation_count = user_participation_count + 1
                                   WHERE user_id = :user_id";
 
    $scanner_count_update_stmt = $db->prepare($scanner_count_update_query);
    $scanner_count_update_stmt->bindParam(':user_id', $scanner_user_id, PDO::PARAM_INT);
    $scanner_count_update_stmt->execute();
 
    // (c) 첫 번째 스캔이면 호스트도 +1
    //     host_credited = 0 인 행만 1로 바꾸는 UPDATE
    //     → affected rows가 1이면 내가 첫 스캔 → 호스트 +1
    //     → affected rows가 0이면 이미 다른 사람이 먼저 스캔 → 호스트 건너뜀
    $host_credit_update_query = "UPDATE meeting_qr_codes
                                 SET host_credited = 1
                                 WHERE qr_id = :qr_id
                                   AND host_credited = 0";
 
    $host_credit_update_stmt = $db->prepare($host_credit_update_query);
    $host_credit_update_stmt->bindParam(':qr_id', $qr_info['qr_id'], PDO::PARAM_INT);
    $host_credit_update_stmt->execute();
 
    $is_first_scan = ($host_credit_update_stmt->rowCount() === 1);
 
    if ($is_first_scan) {
        $host_count_update_query = "UPDATE users
                                    SET user_participation_count = user_participation_count + 1
                                    WHERE user_id = :host_id";
 
        $host_count_update_stmt = $db->prepare($host_count_update_query);
        $host_count_update_stmt->bindParam(':host_id', $qr_info['meeting_host_id'], PDO::PARAM_INT);
        $host_count_update_stmt->execute();
    }
 
    $db->commit();
 
    // ─────────────────────────────────────────────
    // 8. 갱신된 참여자 카운트 조회 후 응답
    // ─────────────────────────────────────────────
    $updated_count_query = "SELECT user_participation_count
                            FROM users
                            WHERE user_id = :user_id";
 
    $updated_count_stmt = $db->prepare($updated_count_query);
    $updated_count_stmt->bindParam(':user_id', $scanner_user_id, PDO::PARAM_INT);
    $updated_count_stmt->execute();
 
    $updated_user = $updated_count_stmt->fetch(PDO::FETCH_ASSOC);
 
    sendSuccess('출석 인증이 완료되었습니다', [
        'participation_count' => (int)$updated_user['user_participation_count']
    ]);
 
  } catch (PDOException $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('출석 인증 중 오류가 발생했습니다: ' . $error->getMessage(), 500);
  }
?>