<?php
/**
 * 모임 목록 조회 API
 * GET /api/meeting/list.php
 * 
 * 쿼리 파라미터:
 * - sigungu: "관악구", "강남구" 등 (선택)
 * - categories: "한식", "중식", "전체" 등 (선택)
 * - status: "모집중", "모집완료" 등 (기본값: "모집중")
 * - is_hot: true/false (인기 모임만, 선택)
 * - page: 페이지 번호 (기본값: 1)
 * - limit: 페이지당 개수 (기본값: 10)
 * 
 * 예시:
 * /api/meeting/list.php?sigungu=관악구&category=한식&page=1&limit=10
 * 
 * 응답:
 * {
 *   "success": true,
 *   "message": "모임 목록 조회 성공",
 *   "data": {
 *     "meetings": [
 *       {
 *         "meeting_id": 1,
 *         "meeting_title": "오늘 저녁 삼겹살 드실 분!",
 *         "meeting_description": "회사 근처에서...",
 *         "meeting_category": "한식",
 *         "meeting_date": "2025-12-22 19:00:00",
 *         "meeting_max_participants": 4,
 *         "meeting_current_participants": 2,
 *         "meeting_price_range": "20,000원",
 *         "meeting_status": "모집중",
 *         "meeting_is_hot": true,
 *         "meeting_view_count": 45,
 *         "meeting_created_at": "2025-12-21 10:00:00",
 *         "location": {
 *           "location_id": 1,
 *           "place_name": "신림 삼겹살집",
 *           "sigungu": "관악구",
 *           "dong": "신림동",
 *           "address": "서울특별시 관악구 신림동 123-45"
 *         },
 *         "host": {
 *           "user_id": 1,
 *           "user_nickname": "김철수",
 *           "user_profile_image": "https://...",
 *           "user_manner_score": 4.5
 *         },
 *         "participants": [
 *           {
 *             "user_id": 1,
 *             "user_nickname": "김철수",
 *             "user_profile_image": "https://..."
 *           },
 *           {
 *             "user_id": 2,
 *             "user_nickname": "이영희",
 *             "user_profile_image": "https://..."
 *           }
 *         ]
 *       }
 *     ],
 *     "total_count": 15,
 *     "page": 1,
 *     "total_pages": 2
 *   }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

// 쿼리 파라미터 가져오기
$sigungu = isset($_GET['sigungu']) ? trim($_GET['sigungu']) : null;
$status = isset($_GET['status']) ? trim($_GET['status']) : '모집중';
$min_price = isset($_GET['min_price']) ? intval($_GET['min_price']) : null;
$max_price = isset($_GET['max_price']) ? intval($_GET['max_price']) : null;
$categories_raw = isset($_GET['categories']) ? trim($_GET['categories']) : null;
$categories = ($categories_raw !== null && $categories_raw !== '')
    ? array_map('trim', explode(',', $categories_raw))
    : null;
$is_hot = isset($_GET['is_hot']) ? filter_var($_GET['is_hot'], FILTER_VALIDATE_BOOLEAN) : null;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 10;
$offset = ($page - 1) * $limit;
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 3.0; //km
$order_by = isset($_GET['order_by']) ? trim($_GET['order_by']) : 'default';

// lat/lng 둘 다 있을 때만 위치 필터 활성화
$useLocationFilter = ($lat !== null && $lng !== null);

// ──────────────────────────────────────────────
//  Bbox(바운딩 박스) 범위 계산
// ──────────────────────────────────────────────

if ($useLocationFilter) {
    $bbox_lat = $radius / 111.0;    //경도
    $bbox_lng = $radius / (111.0 * cos(deg2rad($lat))); // 위도보정

    $bbox_sw = $lat - $bbox_lat;  // 남쪽 위도 하한
    $bbox_ne = $lat + $bbox_lat;  // 북쪽 위도 상한
    $bbox_w  = $lng - $bbox_lng;  // 서쪽 경도 하한
    $bbox_e  = $lng + $bbox_lng;  // 동쪽 경도 상한
}

// ──────────────────────────────────────────────
// WHERE 조건 동적 생성
// ──────────────────────────────────────────────
$where_conditions = [];
$params = [];

if ($status) {
    $where_conditions[] = "m.meeting_status = :status";
    $params[':status'] = $status;
}
$where_conditions[] = "m.meeting_date > NOW()";
if ($sigungu) {
    $where_conditions[] = "l.sigungu = :sigungu";
    $params[':sigungu'] = $sigungu;
}
if ($is_hot !== null) {
    $where_conditions[] = "m.meeting_is_hot = :is_hot";
    $params[':is_hot'] = $is_hot ? 1 : 0;
}
if ($min_price !== null) {
    $where_conditions[] = "CAST(REPLACE(REPLACE(m.meeting_price_range, ',', ''), '원', '') AS UNSIGNED) >= :min_price";
    $params[':min_price'] = $min_price;
}
if ($max_price !== null) {
    $where_conditions[] = "CAST(REPLACE(REPLACE(m.meeting_price_range, ',', ''), '원', '') AS UNSIGNED) <= :max_price";
    $params[':max_price'] = $max_price;
}
// ─── 다중 카테고리 WHERE ─────────────────────────────────────────
// categories 파라미터 있으면 IN절 동적 생성 (1~7개 모두 지원)
if ($categories && count($categories) > 0) {
    $placeholders = implode(',', array_map(fn($i) => ":cat{$i}", array_keys($categories)));
    $where_conditions[] = "m.meeting_category IN ({$placeholders})";
    foreach ($categories as $i => $cat) {
        $params[":cat{$i}"] = $cat;
    }
}

if ($useLocationFilter) {
    // null빈값 행 제거 - haversine 계산 불가 행을 미리 차단
    $where_conditions[] = "l.map_x IS NOT NULL AND l.map_y IS NOT NULL";
    $where_conditions[] = "l.map_x != '' AND l.map_y != ''";

    // bobox 사전 필터 between은 인덱스 활용 가능 풀스캔 방지
    // CAST : map_x/map_y가 varchar로 저장된 경우 숫자 비교를 위해 변환
    $where_conditions[] = "CAST(l.map_y AS DECIMAL(10,7)) BETWEEN :bbox_sw AND :bbox_ne";
    $where_conditions[] = "CAST(l.map_x AS DECIMAL(10,7)) BETWEEN :bbox_w AND :bbox_e";

    $params[':bbox_sw'] = $bbox_sw;
    $params[':bbox_ne'] = $bbox_ne;
    $params[':bbox_w'] = $bbox_w;
    $params[':bbox_e'] = $bbox_e;
}

$where_clause = !empty($where_conditions) 
    ? 'WHERE ' . implode(' AND ' , $where_conditions) : '';


// ──────────────────────────────────────────────
// Haversine SELECT 절 & HAVING 조건
// ──────────────────────────────────────────────

$distance_select = '';
$having_clause = '';

if ($useLocationFilter) {
    $distance_select = ",
    (6371 * ACOS(LEAST(1.0,
        COS(RADIANS(:h_lat)) * COS(RADIANS(CAST(l.map_y AS DECIMAL(10,7)))) *
        COS(RADIANS(CAST(l.map_x AS DECIMAL(10,7))) - RADIANS(:h_lng)) +
        SIN(RADIANS(:h_lat)) * SIN(RADIANS(CAST(l.map_y AS DECIMAL(10,7))))
    ))) AS distance_km";

    $having_clause = "HAVING distance_km <= :h_radius";

    // HAVING에서 쓸 파라미터 — Bbox 파라미터와 키 분리
    $params[':h_lat']    = $lat;
    $params[':h_lng']    = $lng;
    $params[':h_radius'] = $radius;

} else {
    // 위치 필터 없어도 응답 구조 일관성 유지 (Android 파싱 오류 방지)
    $distance_select = ", NULL AS distance_km";
}

// ──────────────────────────────────────────────
//  정렬 조건
// ──────────────────────────────────────────────
if ($order_by === 'distance' && $useLocationFilter) {
    $order_clause = "ORDER BY distance_km ASC";                          // 가까운 순
} else {
    $order_clause = "ORDER BY m.meeting_is_hot DESC, m.meeting_created_at DESC"; // 기존 정렬 유지
}


// ──────────────────────────────────────────────
// DB 연결
// ──────────────────────────────────────────────

$database = new Database();
$db = $database->getConnection();

 // ──────────────────────────────────────────────
    // COUNT 쿼리 (페이지네이션용 전체 개수)
    // ──────────────────────────────────────────────
try {
    if ($useLocationFilter) {
        $count_inner = "
            SELECT m.meeting_id ,
                (6371 * ACOS(LEAST(1.0,
                       COS(RADIANS(:h_lat)) * COS(RADIANS(CAST(l.map_y AS DECIMAL(10,7)))) *
                       COS(RADIANS(CAST(l.map_x AS DECIMAL(10,7))) - RADIANS(:h_lng)) +
                       SIN(RADIANS(:h_lat)) * SIN(RADIANS(CAST(l.map_y AS DECIMAL(10,7))))
                   ))) AS distance_km
            FROM meetings m
            JOIN locations l ON m.location_id = l.location_id
             {$where_clause}
            {$having_clause}
        ";
        
        $count_query = "SELECT COUNT(*) as total FROM ({$count_inner}) AS sub";
    } else {
        $count_query = "
            SELECT COUNT(*) as total
            FROM meetings m
            JOIN locations l ON m.location_id = l.location_id
            {$where_clause}
        ";
    }

    $count_stmt = $db->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key , $value);
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_count / $limit);

     // ──────────────────────────────────────────────
    // 메인 SELECT 쿼리
    // ──────────────────────────────────────────────

    $query = "SELECT
                m.meeting_id,
                m.meeting_title,
                m.meeting_description,
                m.meeting_category,
                m.meeting_date,
                m.meeting_max_participants,
                m.meeting_current_participants,
                m.meeting_price_range,
                m.meeting_status,
                m.meeting_is_hot,
                m.meeting_view_count,
                m.meeting_created_at,
                m.meeting_image,
                l.location_id,
                l.place_name,
                l.sigungu,
                l.dong,
                l.address,
                l.road_address,
                l.map_x,
                l.map_y
                {$distance_select},
                u.user_id           AS host_id,
                u.user_nickname     AS host_nickname,
                u.user_profile_image AS host_profile_image,
                u.user_manner_score  AS host_manner_score
              FROM meetings m
              JOIN locations l ON m.location_id    = l.location_id
              JOIN users     u ON m.meeting_host_id = u.user_id
              {$where_clause}
              {$having_clause}
              {$order_clause}
              LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

     // ──────────────────────────────────────────────
    // 결과 가공 + 참여자 조회
    // ──────────────────────────────────────────────

    $meetings = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        // 참여자 목록 (승인된 사람만) — 기존 로직 그대로
        $participant_query = "SELECT
                                u.user_id,
                                u.user_nickname,
                                u.user_profile_image
                              FROM meeting_participants mp
                              JOIN users u ON mp.user_id = u.user_id
                              WHERE mp.meeting_id = :meeting_id
                              AND mp.participation_status = '승인'
                              ORDER BY mp.applied_at ASC";

        $participant_stmt = $db->prepare($participant_query);
        $participant_stmt->bindParam(':meeting_id', $row['meeting_id']);
        $participant_stmt->execute();
        $participants = $participant_stmt->fetchAll(PDO::FETCH_ASSOC);

        $meetings[] = [
            'meeting_id'                   => (int)$row['meeting_id'],
            'meeting_title'                => $row['meeting_title'],
            'meeting_description'          => $row['meeting_description'],
            'meeting_category'             => $row['meeting_category'],
            'meeting_date'                 => $row['meeting_date'],
            'meeting_max_participants'     => (int)$row['meeting_max_participants'],
            'meeting_current_participants' => (int)$row['meeting_current_participants'],
            'meeting_price_range'          => $row['meeting_price_range'],
            'meeting_status'               => $row['meeting_status'],
            'meeting_is_hot'               => (bool)$row['meeting_is_hot'],
            'meeting_view_count'           => (int)$row['meeting_view_count'],
            'meeting_created_at'           => $row['meeting_created_at'],
            'meeting_image'                => $row['meeting_image'],
            'location' => [
                'location_id'  => (int)$row['location_id'],
                'place_name'   => $row['place_name'],
                'sigungu'      => $row['sigungu'],
                'dong'         => $row['dong'],
                'address'      => $row['address'],
                'road_address' => $row['road_address'],
                'map_x'        => $row['map_x'],  // 경도 (longitude)
                'map_y'        => $row['map_y'],  // 위도 (latitude)
                // 위치 필터 사용 시 계산된 거리, 아니면 null
                'distance_km'  => $row['distance_km'] !== null
                                    ? round(floatval($row['distance_km']), 2) : null,
            ],
            'host' => [
                'user_id'            => (int)$row['host_id'],
                'user_nickname'      => $row['host_nickname'],
                'user_profile_image' => $row['host_profile_image'],
                'user_manner_score'  => (float)$row['host_manner_score'],
            ],
            'participants' => $participants,
        ];
    }
    // ──────────────────────────────────────────────
    //  응답 — 기존 sendSuccess() 그대로 사용
    // ──────────────────────────────────────────────
    sendSuccess('모임 목록 조회 성공', [
        'meetings'    => $meetings,
        'total_count' => (int)$total_count,
        'page'        => $page,
        'total_pages' => (int)$total_pages,
    ]);

} catch (PDOException $e) {
    sendError('모임 목록 조회 실패: ' . $e->getMessage(), 500);
}

?>