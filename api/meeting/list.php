<?php
/**
 * 모임 목록 조회 API
 * GET /api/meeting/list.php
 *
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

// ── 기존 파라미터 (그대로) ───────────────────────────────────
$sigungu         = isset($_GET['sigungu'])    ? trim($_GET['sigungu'])    : null;
$status          = isset($_GET['status'])     ? trim($_GET['status'])     : '모집중';
$min_price       = isset($_GET['min_price'])  ? intval($_GET['min_price']) : null;
$max_price       = isset($_GET['max_price'])  ? intval($_GET['max_price']) : null;
$categories_raw  = isset($_GET['categories']) ? trim($_GET['categories']) : null;
$categories      = ($categories_raw !== null && $categories_raw !== '')
                    ? array_map('trim', explode(',', $categories_raw))
                    : null;
$is_hot          = isset($_GET['is_hot']) ? filter_var($_GET['is_hot'], FILTER_VALIDATE_BOOLEAN) : null;
$page            = isset($_GET['page'])   ? max(1, intval($_GET['page'])) : 1;
$limit           = isset($_GET['limit'])  ? min(50, max(1, intval($_GET['limit']))) : 10;
$offset          = ($page - 1) * $limit;
$lat             = isset($_GET['lat'])    ? floatval($_GET['lat']) : null;
$lng             = isset($_GET['lng'])    ? floatval($_GET['lng']) : null;
$radius          = isset($_GET['radius']) ? floatval($_GET['radius']) : 5.0;
$order_by        = isset($_GET['order_by']) ? trim($_GET['order_by']) : null;

// ── 체험단 신규 파라미터 ─────────────────────────────────────
// meeting_type 미지정 시 기본값 '일반' → 홈 기존 동작 유지(회귀 0)
// MapFragment는 일반+체험단을 한 번에 보여줘야 하므로 meeting_type=전체를
// 명시적으로 보낼 수 있다 → 이 경우 meeting_type WHERE 조건 자체를 생략
$meeting_type = isset($_GET['meeting_type']) ? trim($_GET['meeting_type']) : '일반';
// 체험단 채널 필터 (체험단 탭 + 지도 공용, 선택)
$channels_filter = isset($_GET['channels']) ? trim($_GET['channels']) : null;

// ── 지역 필터 신규 파라미터 (시/도 → 구 2단계) ───────────────
$sido = isset($_GET['sido']) ? trim($_GET['sido']) : null;

// ── 지도 날짜/시간 필터 신규 파라미터 ────────────────────────
// date: YYYY-MM-DD, time: HH:MM (둘 다 선택)
$date_filter = isset($_GET['date']) ? trim($_GET['date']) : null;
$time_filter = isset($_GET['time']) ? trim($_GET['time']) : null;

// date 형식 검증 (YYYY-MM-DD)
if ($date_filter !== null && $date_filter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) {
    sendError('date 파라미터 형식이 올바르지 않습니다 (YYYY-MM-DD)');
}

// time 형식 검증 (HH:MM)
if ($time_filter !== null && $time_filter !== '' && !preg_match('/^\d{2}:\d{2}$/', $time_filter)) {
    sendError('time 파라미터 형식이 올바르지 않습니다 (HH:MM)');
}

// meeting_type 유효성 ('전체'는 일반+체험단 모두 조회 — MapFragment 전용)
if (!in_array($meeting_type, ['일반', '체험단', '전체'])) {
    sendError('meeting_type은 일반, 체험단, 전체 중 하나여야 합니다');
}

// ── WHERE 조건 조립 ──────────────────────────────────────────
$where_conditions = [];
$params = [];

// meeting_type 필터 ('전체'면 일반+체험단 모두 포함하도록 조건 생략)
if ($meeting_type !== '전체') {
    $where_conditions[] = "m.meeting_type = :meeting_type";
    $params[':meeting_type'] = $meeting_type;
}

// 모임 상태
$where_conditions[] = "m.meeting_status = :status";
$params[':status'] = $status;

// 시/도 (sido)
if ($sido !== null && $sido !== '') {
    $where_conditions[] = "l.sido = :sido";
    $params[':sido'] = $sido;
}

// 지역 (구)
if ($sigungu !== null && $sigungu !== '') {
    $where_conditions[] = "l.sigungu = :sigungu";
    $params[':sigungu'] = $sigungu;
}

// 인기 모임
if ($is_hot !== null) {
    $where_conditions[] = "m.meeting_is_hot = :is_hot";
    $params[':is_hot'] = $is_hot ? 1 : 0;
}

// 가격
if ($min_price !== null) {
    $where_conditions[] = "CAST(REPLACE(REPLACE(m.meeting_price_range, ',', ''), '원', '') AS UNSIGNED) >= :min_price";
    $params[':min_price'] = $min_price;
}
if ($max_price !== null) {
    $where_conditions[] = "CAST(REPLACE(REPLACE(m.meeting_price_range, ',', ''), '원', '') AS UNSIGNED) <= :max_price";
    $params[':max_price'] = $max_price;
}

// 카테고리 (다중)
if ($categories && count($categories) > 0) {
    $placeholders = implode(',', array_map(fn($i) => ":cat{$i}", array_keys($categories)));
    $where_conditions[] = "m.meeting_category IN ({$placeholders})";
    foreach ($categories as $index => $cat) {
        $params[":cat{$index}"] = $cat;
    }
}

// 체험단 채널 필터
// 채널 필터가 걸리면 meeting_type 값과 무관하게 항상
// "campaign_channels LIKE '%값%'" 조건만 적용한다.
// campaign_channels는 일반 모임에서 항상 NULL이므로, 이 조건이 걸리는
// 순간 일반 모임은 자동으로 결과에서 제외된다(의도된 동작 — 사용자가
// 특정 홍보 채널을 선택했다면 그 채널을 쓰는 체험단만 보고 싶은 것).
if ($channels_filter !== null && $channels_filter !== '') {
    $where_conditions[] = "m.campaign_channels LIKE :channels_filter";
    $params[':channels_filter'] = '%' . $channels_filter . '%';
}

// 날짜 필터 (meeting_date의 날짜 부분만 비교)
if ($date_filter !== null && $date_filter !== '') {
    $where_conditions[] = "DATE(m.meeting_date) = :date_filter";
    $params[':date_filter'] = $date_filter;
}

// 시간 필터 (해당 날짜 내 시작 시각 이상 — 단순화된 시작시각 필터)
if ($time_filter !== null && $time_filter !== '') {
    $where_conditions[] = "TIME(m.meeting_date) >= :time_filter";
    $params[':time_filter'] = $time_filter . ':00';
}

// 위치 필터 (기존 Haversine 그대로)
$useLocationFilter = ($lat !== null && $lng !== null);
if ($useLocationFilter) {
    $bbox_offset  = $radius / 111.0;
    $bbox_sw      = $lat - $bbox_offset;
    $bbox_ne      = $lat + $bbox_offset;
    $bbox_w       = $lng - $bbox_offset;
    $bbox_e       = $lng + $bbox_offset;

    $where_conditions[] = "l.map_x IS NOT NULL AND l.map_y IS NOT NULL";
    $where_conditions[] = "l.map_x != '' AND l.map_y != ''";
    $where_conditions[] = "CAST(l.map_y AS DECIMAL(10,7)) BETWEEN :bbox_sw AND :bbox_ne";
    $where_conditions[] = "CAST(l.map_x AS DECIMAL(10,7)) BETWEEN :bbox_w AND :bbox_e";

    $params[':bbox_sw'] = $bbox_sw;
    $params[':bbox_ne'] = $bbox_ne;
    $params[':bbox_w']  = $bbox_w;
    $params[':bbox_e']  = $bbox_e;
}

$where_clause = !empty($where_conditions)
    ? 'WHERE ' . implode(' AND ', $where_conditions)
    : '';

// ── Haversine SELECT / HAVING (기존 그대로) ──────────────────
$distance_select = '';
$having_clause   = '';

if ($useLocationFilter) {
    $distance_select = ",
    (6371 * ACOS(LEAST(1.0,
        COS(RADIANS(:h_lat)) * COS(RADIANS(CAST(l.map_y AS DECIMAL(10,7)))) *
        COS(RADIANS(CAST(l.map_x AS DECIMAL(10,7))) - RADIANS(:h_lng)) +
        SIN(RADIANS(:h_lat)) * SIN(RADIANS(CAST(l.map_y AS DECIMAL(10,7))))
    ))) AS distance_km";
    $having_clause = "HAVING distance_km <= :h_radius";
    $params[':h_lat']    = $lat;
    $params[':h_lng']    = $lng;
    $params[':h_radius'] = $radius;
} else {
    $distance_select = ", NULL AS distance_km";
}

// ── 정렬 (기존 + 체험단 마감임박순 추가) ────────────────────
if ($order_by === 'distance' && $useLocationFilter) {
    $order_clause = "ORDER BY distance_km ASC";
} elseif ($order_by === 'deadline' && $meeting_type === '체험단') {
    $order_clause = "ORDER BY m.campaign_deadline ASC";
} else {
    $order_clause = "ORDER BY m.meeting_is_hot DESC, m.meeting_created_at DESC";
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

try {
    // COUNT 쿼리
    if ($useLocationFilter) {
        $count_inner = "
            SELECT m.meeting_id,
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
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_count / $limit);

    // ── 메인 SELECT (campaign 컬럼 + sido 추가) ────────────────
    $query = "SELECT
                m.meeting_id,
                m.meeting_type,
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
                m.campaign_channels,
                m.campaign_provided,
                m.campaign_requirements,
                m.campaign_deadline,
                l.location_id,
                l.place_name,
                l.sido,
                l.sigungu,
                l.dong,
                l.address,
                l.road_address,
                l.map_x,
                l.map_y
                {$distance_select},
                u.user_id            AS host_id,
                u.user_nickname      AS host_nickname,
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

    $meetings = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // 참여자 목록 (기존 그대로)
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

        $meeting_data = [
            'meeting_id'                  => (int)$row['meeting_id'],
            'meeting_type'                => $row['meeting_type'],
            'meeting_title'               => $row['meeting_title'],
            'meeting_description'         => $row['meeting_description'],
            'meeting_category'            => $row['meeting_category'],
            'meeting_date'                => $row['meeting_date'],
            'meeting_max_participants'    => (int)$row['meeting_max_participants'],
            'meeting_current_participants' => (int)$row['meeting_current_participants'],
            'meeting_price_range'         => $row['meeting_price_range'],
            'meeting_status'              => $row['meeting_status'],
            'meeting_is_hot'              => (bool)$row['meeting_is_hot'],
            'meeting_view_count'          => (int)$row['meeting_view_count'],
            'meeting_created_at'          => $row['meeting_created_at'],
            'meeting_image'               => $row['meeting_image'],
            // 체험단 전용 필드
            'campaign_channels'           => $row['campaign_channels'],
            'campaign_provided'           => $row['campaign_provided'],
            'campaign_requirements'       => $row['campaign_requirements'],
            'campaign_deadline'           => $row['campaign_deadline'],
            'location' => [
                'location_id'  => (int)$row['location_id'],
                'place_name'   => $row['place_name'],
                'sido'         => $row['sido'],
                'sigungu'      => $row['sigungu'],
                'dong'         => $row['dong'],
                'address'      => $row['address'],
                'road_address' => $row['road_address'],
                'map_x'        => $row['map_x'],
                'map_y'        => $row['map_y'],
            ],
            'host' => [
                'user_id'           => (int)$row['host_id'],
                'user_nickname'     => $row['host_nickname'],
                'user_profile_image' => $row['host_profile_image'],
                'user_manner_score' => (float)$row['host_manner_score'],
            ],
            'participants'   => $participants,
            'distance_km'    => isset($row['distance_km']) ? round((float)$row['distance_km'], 2) : null,
        ];

        $meetings[] = $meeting_data;
    }

    sendSuccess('모임 목록 조회 성공', [
        'meetings'    => $meetings,
        'total_count' => (int)$total_count,
        'page'        => $page,
        'total_pages' => (int)$total_pages,
        'meeting_type' => $meeting_type,
    ]);

} catch (PDOException $e) {
    sendError('모임 목록 조회 실패: ' . $e->getMessage(), 500);
}
?>