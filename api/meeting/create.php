<?php
/**
 * 모임 생성 API
 * POST /api/meeting/create.php
 * 
 * 요청:
 * {
 *   "user_id": 1,
 *   "meeting_title": "오늘 저녁 삼겹살 드실 분!",
 *   "meeting_description": "회사 근처에서...",
 *   "meeting_category": "한식",
 *   "meeting_date": "2025-12-22 19:00:00",
 *   "meeting_max_participants": 4,
 *   "meeting_price_range": "20,000원",
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
 * 응답:
 * {
 *   "success": true,
 *   "message": "모임이 생성되었습니다",
 *   "data": {
 *     "meeting_id": 5,
 *     "location_id": 3
 *   }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// 필수 필드 확인
$required_fields = [
    'user_id', 
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
$user_id = intval($input['user_id']);
$meeting_title = trim($input['meeting_title']);
$meeting_description = trim($input['meeting_description']);
$meeting_category = trim($input['meeting_category']);
$meeting_date = trim($input['meeting_date']);
$meeting_max_participants = intval($input['meeting_max_participants']);
$meeting_price_range = trim($input['meeting_price_range']);

// meeting_image 옵셔널 처리 (없으면 null)
$meeting_image = isset($input['meeting_image']) && !empty(trim($input['meeting_image']))
    ? trim($input['meeting_image'])
    : null;
 

// 유효성 검증
if (strlen($meeting_title) < 5 || strlen($meeting_title) > 100) {
    sendError('모임 제목은 5-100자 이내로 입력해주세요');
}

if (strlen($meeting_description) < 10) {
    sendError('모임 설명은 최소 10자 이상 입력해주세요');
}

if ($meeting_max_participants < 2 || $meeting_max_participants > 10) {
    sendError('최대 참여 인원은 2-10명 사이로 설정해주세요');
}

// 날짜 검증 (과거 날짜 불가)
$meeting_datetime = strtotime($meeting_date);
if ($meeting_datetime === false || $meeting_datetime < time()) {
    sendError('모임 날짜는 현재 시간 이후로 설정해주세요');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

try {
    // 트랜잭션 시작
    $db->beginTransaction();
    
    // 1. location 중복 확인 (같은 장소가 이미 있는지)
    $location_check_query = "SELECT location_id FROM locations
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
    } else {
        // 새 location 생성
        
        // NULL 가능한 필드들을 변수에 먼저 담기
        $address = $location['address'] ?? null;
        $road_address = $location['road_address'] ?? null;
        $sido = $location['sido'] ?? null;
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
    
    // 2. meeting 생성
    $meeting_insert_query = "INSERT INTO meetings (
        meeting_host_id, meeting_title, meeting_description, meeting_category,
        meeting_date, meeting_max_participants, meeting_current_participants,
        meeting_price_range, meeting_image, location_id, meeting_status
    ) VALUES (
        :host_id, :title, :description, :category,
        :date, :max_participants, 1,
        :price_range, :meeting_image, :location_id, '모집중'
    )";
    
    $meeting_insert_stmt = $db->prepare($meeting_insert_query);
    $meeting_insert_stmt->bindParam(':host_id', $user_id);
    $meeting_insert_stmt->bindParam(':title', $meeting_title);
    $meeting_insert_stmt->bindParam(':description', $meeting_description);
    $meeting_insert_stmt->bindParam(':category', $meeting_category);
    $meeting_insert_stmt->bindParam(':date', $meeting_date);
    $meeting_insert_stmt->bindParam(':max_participants', $meeting_max_participants);
    $meeting_insert_stmt->bindParam(':price_range', $meeting_price_range);
    $meeting_insert_stmt->bindParam(':meeting_image', $meeting_image);
    $meeting_insert_stmt->bindParam(':location_id', $location_id);
    $meeting_insert_stmt->execute();
    
    $meeting_id = $db->lastInsertId();
    
    // 3. 호스트를 자동으로 참여자로 추가 (승인 상태)
    $participant_insert_query = "INSERT INTO meeting_participants (
        meeting_id, user_id, participation_status, approved_at, approved_by
    ) VALUES (
        :meeting_id, :user_id, '승인', NOW(), :approved_by
    )";
    
    $participant_insert_stmt = $db->prepare($participant_insert_query);
    $participant_insert_stmt->bindParam(':meeting_id', $meeting_id);
    $participant_insert_stmt->bindParam(':user_id', $user_id);
    $participant_insert_stmt->bindParam(':approved_by', $user_id);
    $participant_insert_stmt->execute();
    
    // 트랜잭션 커밋
    $db->commit();
    
    sendSuccess('모임이 생성되었습니다', [
        'meeting_id' => $meeting_id,
        'location_id' => $location_id
    ]);
    
} catch(PDOException $e) {
    // 트랜잭션 롤백
    $db->rollBack();
    sendError('모임 생성 실패: ' . $e->getMessage(), 500);
}
?>