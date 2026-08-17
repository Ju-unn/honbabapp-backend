<?php
/**
 * 모임 수정 API
 * POST /api/meeting/update.php
 *
 * 요청:
 * {
 *   "meeting_id": 1,
 *   "user_id": 1,
 *   "meeting_title": "수정된 제목",
 *   "meeting_description": "수정된 설명",
 *   "meeting_category": "한식",
 *   "meeting_date": "2025-12-22 19:00:00",
 *   "meeting_max_participants": 4,
 *   "meeting_price_range": "20,000원",
 *   "meeting_image": "https://...",  (선택)
 *   "location": {
 *     "place_name": "신림 삼겹살집",
 *     "address": "서울특별시 관악구 신림동 123-45",
 *     "road_address": "서울특별시 관악구 신림로 340",
 *     "sido": "서울특별시",
 *     "sigungu": "관악구",
 *     "dong": "신림동",
 *     "map_x": "126.929810",
 *     "map_y": "37.484089",
 *     "category": "음식점>한식>고기구이",
 *     "phone_number": "02-1234-5678"
 *   }
 * }
 *
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     sendError('POST 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
 $host_id = get_user_id_from_token();
 
 $input = json_decode(file_get_contents('php://input'), true);
 
 // 필수 필드 확인
 $required_fields = [
     'meeting_id',
     'meeting_title',
     'meeting_description',
     'meeting_category',
     'meeting_date',
     'meeting_max_participants',
     'meeting_price_range',
     'location'
 ];
 
 foreach ($required_fields as $field) {
     if (empty($input[$field])) {
         sendError("{$field} 필드는 필수입니다");
     }
 }
 
 // location 필수 필드 확인
 $location = $input['location'];
 $required_location_fields = ['place_name', 'sigungu'];
 
 foreach ($required_location_fields as $field) {
     if (empty($location[$field])) {
         sendError("location.{$field} 필드는 필수입니다");
     }
 }
 
 // 입력값 정리
 $meeting_id = intval($input['meeting_id']);
$meeting_title = trim($input['meeting_title']);
$meeting_description = trim($input['meeting_description']);
$meeting_category = trim($input['meeting_category']);
$meeting_date = trim($input['meeting_date']);
$meeting_max_participants = intval($input['meeting_max_participants']);
$meeting_price_range = trim($input['meeting_price_range']);
$meeting_image = isset($input['meeting_image']) && !empty(trim($input['meeting_image']))
    ? trim($input['meeting_image'])
    : null;

// 유효성 검증
if (mb_strlen($meeting_title) < 5 || mb_strlen($meeting_title) > 30) {
  sendError('모임 제목은 5-30자 이내로 입력해주세요');
}

if (mb_strlen($meeting_description) < 10 || mb_strlen($meeting_description) > 100) {
  sendError('모임 설명은 최소 10~100자 이상 입력해주세요');
}

if ($meeting_max_participants < 2 || $meeting_max_participants > 10) {
    sendError('최대 참여 인원은 2-10명 사이로 설정해주세요');
}

// 날짜 형식만 여기서 검증하고, "현재 시간 이후" 검증은 DB의 만료 검사 뒤로 미룬다
// (만료된 모임을 과거 날짜 그대로 수정 시도하면 날짜 문구가 아니라
//  "이미 종료된 모임" 문구가 떠야 원인이 명확함)
$meeting_datetime = strtotime($meeting_date);
if ($meeting_datetime === false) {
    sendError('모임 날짜 형식이 올바르지 않습니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // 모임 조회 + 호스트 검증 + 현재 참여자 수 확인
    // 만료 여부는 조회 조건에 넣지 않고 따로 검사한다
    // (조건에 섞으면 만료된 모임도 "찾을 수 없거나 권한 없음"으로 뭉뚱그려져 원인을 알 수 없음)
    $stmt_check = $db->prepare("SELECT meeting_current_participants,
                                        meeting_status,
                                        (meeting_date <= NOW()) AS is_expired
                                 FROM meetings
                                 WHERE meeting_id = :meeting_id
                                 AND meeting_host_id = :host_id");
    $stmt_check->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':host_id', $host_id, PDO::PARAM_INT);
    $stmt_check->execute();

    if ($stmt_check->rowCount() === 0) {
        sendError('모임을 찾을 수 없거나 수정 권한이 없습니다');
    }

    $meeting = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (intval($meeting['is_expired']) === 1) {
        sendError('이미 종료된 모임이라 수정할 수 없습니다');
    }

    // 만료 검사를 통과한 뒤에만 새 날짜의 미래 여부를 검증
    if ($meeting_datetime < time()) {
        sendError('모임 날짜는 현재 시간 이후로 설정해주세요');
    }

    // 최대 참여 인원이 현재 참여자 수보다 작으면 안 됨
    // 예: 이미 3명이 있는데 최대를 2명으로 줄이면 안 됨
    if ($meeting_max_participants < $meeting['meeting_current_participants']) {
        sendError('최대 참여 인원은 현재 참여자 수(' . $meeting['meeting_current_participants'] . '명)보다 작을 수 없습니다');
    }

    $db->beginTransaction();

    // ── 요청으로 들어온 sido 값 (NULL/빈 문자열일 수 있음) ──────
    $incoming_sido = isset($location['sido']) ? trim($location['sido']) : null;
    if ($incoming_sido === '') {
        $incoming_sido = null;
    }

    // 2. location: create.php와 동일 — place_name + sigungu로 기존 행 재사용 또는 신규 INSERT
    //    (조회 조건은 그대로 두어, 같은 가게가 sido 유무로 중복 생성되지 않게 한다)
    $location_check_query = "SELECT location_id, sido FROM locations 
                             WHERE place_name = :place_name 
                             AND sigungu = :sigungu 
                             LIMIT 1";
    $location_check_stmt = $db->prepare($location_check_query);
    $location_check_stmt->bindParam(':place_name', $location['place_name']);
    $location_check_stmt->bindParam(':sigungu', $location['sigungu']);
    $location_check_stmt->execute();

    if ($location_check_stmt->rowCount() > 0) {
        // 기존 location 사용
        $existing_location = $location_check_stmt->fetch(PDO::FETCH_ASSOC);
        $location_id = $existing_location['location_id'];

        // ── sido 점진적 backfill ──────────────────────────────
        // 기존 행의 sido가 비어있고(NULL/빈 문자열), 이번 요청에
        // 새로운 sido 값이 들어왔다면 보정한다. 이미 sido가 채워진
        // 행은 덮어쓰지 않는다(기존 정상 데이터 보호).
        $existing_sido = $existing_location['sido'];
        $existing_sido_is_empty = ($existing_sido === null || trim((string)$existing_sido) === '');

        if ($existing_sido_is_empty && $incoming_sido !== null) {
            $backfill_stmt = $db->prepare(
                "UPDATE locations SET sido = :sido WHERE location_id = :location_id"
            );
            $backfill_stmt->bindParam(':sido', $incoming_sido);
            $backfill_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
            $backfill_stmt->execute();
        }
    } else {
        // 새 location 생성
        
        // NULL 가능한 필드들을 변수에 먼저 담기
        $address = $location['address'] ?? null;
        $road_address = $location['road_address'] ?? null;
        $sido = $incoming_sido;
        $dong = $location['dong'] ?? null;
        $map_x = $location['map_x'] ?? null;
        $map_y = $location['map_y'] ?? null;
        $category = $location['category'] ?? null;
        $phone_number = $location['phone_number'] ?? null;

        $location_insert_query = "INSERT INTO locations (
            place_name, address, road_address, sido, sigungu, dong,
            map_x, map_y, category, phone_number
        ) VALUES (
            :place_name, :address, :road_address, :sido, :sigungu, :dong,
            :map_x, :map_y, :category, :phone_number
        )";

        $location_insert_stmt = $db->prepare($location_insert_query);
        $location_insert_stmt->bindParam(':place_name', $location['place_name']);
        $location_insert_stmt->bindParam(':address', $address);
        $location_insert_stmt->bindParam(':road_address', $road_address);
        $location_insert_stmt->bindParam(':sido', $sido);
        $location_insert_stmt->bindParam(':sigungu', $location['sigungu']);
        $location_insert_stmt->bindParam(':dong', $dong);
        $location_insert_stmt->bindParam(':map_x', $map_x);
        $location_insert_stmt->bindParam(':map_y', $map_y);
        $location_insert_stmt->bindParam(':category', $category);
        $location_insert_stmt->bindParam(':phone_number', $phone_number);
        $location_insert_stmt->execute();

        $location_id = $db->lastInsertId();
    }

    // 3. meeting 업데이트 (location_id는 위에서 해석한 값으로만 변경)
    $stmt_meeting = $db->prepare("UPDATE meetings SET
        meeting_title = :title,
        meeting_description = :description,
        meeting_category = :category,
        meeting_date = :date,
        meeting_max_participants = :max_participants,
        meeting_price_range = :price_range,
        meeting_image = :meeting_image,
        location_id = :location_id
    WHERE meeting_id = :meeting_id
    AND meeting_host_id = :host_id");

    $stmt_meeting->bindParam(':title', $meeting_title);
    $stmt_meeting->bindParam(':description', $meeting_description);
    $stmt_meeting->bindParam(':category', $meeting_category);
    $stmt_meeting->bindParam(':date', $meeting_date);
    $stmt_meeting->bindParam(':max_participants', $meeting_max_participants);
    $stmt_meeting->bindParam(':price_range', $meeting_price_range);
    $stmt_meeting->bindParam(':meeting_image', $meeting_image);
    $stmt_meeting->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    $stmt_meeting->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt_meeting->bindParam(':host_id', $host_id, PDO::PARAM_INT);
    $stmt_meeting->execute();

    // 3-1. 그룹 채팅방 동기화
    //      room_name/room_image는 채팅방 생성 시점(approve.php)에 모임 제목/이미지를
    //      복사해 넣은 값이라, 모임 수정 시 함께 갱신하지 않으면 옛날 값이 계속 남는다.
    //      이미지는 새 값이 있을 때만 덮어쓴다 (null로 기존 이미지를 지우지 않음)
    $stmt_room = $db->prepare("
        UPDATE chat_rooms
        SET room_name  = :room_name,
            room_image = COALESCE(:room_image, room_image)
        WHERE meeting_id = :meeting_id
          AND room_type  = 'group'
    ");
    $stmt_room->execute([
        ':room_name'  => $meeting_title,
        ':room_image' => $meeting_image,
        ':meeting_id' => $meeting_id,
    ]);

    // 4. 정원 변경에 따른 모집 상태 재계산
    //    - 모집완료였는데 최대인원을 늘려 자리가 생기면 → 모집중 복귀
    //      (approve.php가 정원 도달 시 모집완료로 바꾸는 것의 역방향)
    //    - 모집중인데 최대인원을 현재 인원까지 줄이면 → 모집완료
    //    취소 등 다른 상태는 건드리지 않는다
    $current_participants = intval($meeting['meeting_current_participants']);

    if ($meeting['meeting_status'] === '모집완료'
        && $meeting_max_participants > $current_participants) {
        $db->prepare("UPDATE meetings SET meeting_status = '모집중' WHERE meeting_id = :meeting_id")
           ->execute([':meeting_id' => $meeting_id]);
    } elseif ($meeting['meeting_status'] === '모집중'
        && $meeting_max_participants <= $current_participants) {
        $db->prepare("UPDATE meetings SET meeting_status = '모집완료' WHERE meeting_id = :meeting_id")
           ->execute([':meeting_id' => $meeting_id]);
    }

    $db->commit();
    sendSuccess('모임이 수정되었습니다', [
        'meeting_id' => $meeting_id,
        'location_id' => $location_id
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('모임 수정 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
}
?>