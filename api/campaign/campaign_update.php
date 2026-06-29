<?php
/**
 * 체험단 수정 API
 * POST /api/campaign/update.php
 *
 * 기존 api/meeting/update.php 구조 기반에서
 * campaign 4개 필드(channels, provided, requirements, deadline) 추가
 *
 * 요청:
 * {
 *   "meeting_id": 1,
 *   "user_id": 1,
 *   "meeting_title": "수정된 체험단 제목",
 *   "meeting_description": "수정된 설명",
 *   "meeting_category": "한식",
 *   "meeting_date": "2025-12-22 19:00:00",
 *   "meeting_max_participants": 5,
 *   "meeting_price_range": "20,000원 ~ 40,000원",
 *   "meeting_image": "https://...",
 *   "location": {
 *     "place_name": "...",
 *     "address": "...",
 *     "road_address": "...",
 *     "sido": "...",
 *     "sigungu": "...",
 *     "dong": "...",
 *     "map_x": "...",
 *     "map_y": "...",
 *     "category": "...",
 *     "phone_number": "..."
 *   },
 *   "campaign_channels": "블로그,인스타그램",
 *   "campaign_provided": "2인 세트 무료 제공",
 *   "campaign_requirements": "방문 후 2주 이내 업로드",
 *   "campaign_deadline": "2025-12-01 00:00:00"
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// ── 공통 필수 필드 확인 ──────────────────────────────────────
$required_fields = [
    'meeting_id', 'user_id', 'meeting_title', 'meeting_description',
    'meeting_category', 'meeting_max_participants',
    'meeting_price_range', 'location'
];

foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        sendError("{$field} 필드는 필수입니다");
    }
}

// ── location 필수 필드 확인 ──────────────────────────────────
$location = $input['location'];
foreach (['place_name', 'sigungu'] as $field) {
    if (empty($location[$field])) {
        sendError("location.{$field} 필드는 필수입니다");
    }
}

// ── 체험단 필수 필드 확인 ────────────────────────────────────
if (empty($input['campaign_channels'])) {
    sendError('campaign_channels 필드는 필수입니다');
}
if (empty($input['campaign_provided'])) {
    sendError('campaign_provided 필드는 필수입니다');
}

// ── 입력값 정리 ──────────────────────────────────────────────
$meeting_id               = intval($input['meeting_id']);
$host_id                  = intval($input['user_id']);
$meeting_title            = trim($input['meeting_title']);
$meeting_description      = trim($input['meeting_description']);
$meeting_category         = trim($input['meeting_category']);
// 체험단 수정 — meeting_date = campaign_deadline (모집 종료 기준)
// deadline 없으면 등록일 기준 30일 후 기본값
if (!empty($campaign_deadline)) {
    $meeting_date = $campaign_deadline;
} else {
    $meeting_date = date('Y-m-d H:i:s', strtotime('+30 days'));
}
$meeting_max_participants = intval($input['meeting_max_participants']);
$meeting_price_range      = trim($input['meeting_price_range']);
$meeting_image            = isset($input['meeting_image']) && !empty(trim($input['meeting_image']))
    ? trim($input['meeting_image'])
    : null;

// campaign 전용 필드
$campaign_channels     = trim($input['campaign_channels']);
$campaign_provided     = trim($input['campaign_provided']);
$campaign_requirements = isset($input['campaign_requirements']) && !empty(trim($input['campaign_requirements']))
    ? trim($input['campaign_requirements'])
    : null;
$campaign_deadline     = isset($input['campaign_deadline']) && !empty(trim($input['campaign_deadline']))
    ? trim($input['campaign_deadline'])
    : null;

// ── 유효성 검증 ──────────────────────────────────────────────
if (mb_strlen($meeting_title) < 2 || mb_strlen($meeting_title) > 50) {
    sendError('체험단 제목은 2~50자 이내로 입력해주세요');
}

if (mb_strlen($meeting_description) < 5 || mb_strlen($meeting_description) > 500) {
    sendError('체험단 설명은 5~500자 이내로 입력해주세요');
}

// 체험단 인원 상한 2~20
if ($meeting_max_participants < 2 || $meeting_max_participants > 20) {
    sendError('최대 참여 인원은 2~20명 사이로 설정해주세요');
}

// ── DB 연결 ──────────────────────────────────────────────────
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // ── 1. 모임 조회 + 호스트 권한 확인 + 체험단 여부 확인 ────
    $stmt_check = $db->prepare("
        SELECT meeting_current_participants, meeting_type
        FROM meetings
        WHERE meeting_id    = :meeting_id
          AND meeting_host_id = :host_id
    ");
    $stmt_check->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':host_id',    $host_id,    PDO::PARAM_INT);
    $stmt_check->execute();

    if ($stmt_check->rowCount() === 0) {
        sendError('체험단을 찾을 수 없거나 수정 권한이 없습니다');
    }

    $meeting_row = $stmt_check->fetch(PDO::FETCH_ASSOC);

    // 체험단이 아닌 모임으로 수정 시도 방어
    if ($meeting_row['meeting_type'] !== '체험단') {
        sendError('체험단만 이 API로 수정할 수 있습니다');
    }

    // 최대 인원이 현재 참여자 수보다 작으면 안 됨
    if ($meeting_max_participants < $meeting_row['meeting_current_participants']) {
        sendError('최대 참여 인원은 현재 참여자 수(' . $meeting_row['meeting_current_participants'] . '명)보다 작을 수 없습니다');
    }

    $db->beginTransaction();

    // ── 2. location: place_name + sigungu 로 기존 행 재사용 또는 신규 INSERT ──
    $location_check_stmt = $db->prepare("
        SELECT location_id FROM locations
        WHERE place_name = :place_name
          AND sigungu    = :sigungu
        LIMIT 1
    ");
    $location_check_stmt->bindParam(':place_name', $location['place_name']);
    $location_check_stmt->bindParam(':sigungu',    $location['sigungu']);
    $location_check_stmt->execute();

    if ($location_check_stmt->rowCount() > 0) {
        $location_id = $location_check_stmt->fetch(PDO::FETCH_ASSOC)['location_id'];
    } else {
        $address      = $location['address']      ?? null;
        $road_address = $location['road_address']  ?? null;
        $sido         = $location['sido']          ?? null;
        $dong         = $location['dong']          ?? null;
        $map_x        = $location['map_x']         ?? null;
        $map_y        = $location['map_y']         ?? null;
        $category     = $location['category']      ?? null;
        $phone_number = $location['phone_number']  ?? null;

        $location_insert_stmt = $db->prepare("
            INSERT INTO locations (
                place_name, address, road_address, sido, sigungu, dong,
                map_x, map_y, category, phone_number
            ) VALUES (
                :place_name, :address, :road_address, :sido, :sigungu, :dong,
                :map_x, :map_y, :category, :phone_number
            )
        ");
        $location_insert_stmt->bindParam(':place_name',   $location['place_name']);
        $location_insert_stmt->bindParam(':address',      $address);
        $location_insert_stmt->bindParam(':road_address', $road_address);
        $location_insert_stmt->bindParam(':sido',         $sido);
        $location_insert_stmt->bindParam(':sigungu',      $location['sigungu']);
        $location_insert_stmt->bindParam(':dong',         $dong);
        $location_insert_stmt->bindParam(':map_x',        $map_x);
        $location_insert_stmt->bindParam(':map_y',        $map_y);
        $location_insert_stmt->bindParam(':category',     $category);
        $location_insert_stmt->bindParam(':phone_number', $phone_number);
        $location_insert_stmt->execute();

        $location_id = $db->lastInsertId();
    }

    // ── 3. meetings UPDATE (campaign 4개 컬럼 포함) ─────────────
    $stmt_meeting = $db->prepare("
        UPDATE meetings SET
            meeting_title            = :title,
            meeting_description      = :description,
            meeting_category         = :category,
            meeting_date             = :date,
            meeting_max_participants = :max_participants,
            meeting_price_range      = :price_range,
            meeting_image            = :meeting_image,
            location_id              = :location_id,
            campaign_channels        = :campaign_channels,
            campaign_provided        = :campaign_provided,
            campaign_requirements    = :campaign_requirements,
            campaign_deadline        = :campaign_deadline
        WHERE meeting_id      = :meeting_id
          AND meeting_host_id = :host_id
    ");

    $stmt_meeting->bindParam(':title',                $meeting_title);
    $stmt_meeting->bindParam(':description',          $meeting_description);
    $stmt_meeting->bindParam(':category',             $meeting_category);
    $stmt_meeting->bindParam(':date',                 $meeting_date);
    $stmt_meeting->bindParam(':max_participants',     $meeting_max_participants);
    $stmt_meeting->bindParam(':price_range',          $meeting_price_range);
    $stmt_meeting->bindParam(':meeting_image',        $meeting_image);
    $stmt_meeting->bindParam(':location_id',          $location_id, PDO::PARAM_INT);
    $stmt_meeting->bindParam(':campaign_channels',    $campaign_channels);
    $stmt_meeting->bindParam(':campaign_provided',    $campaign_provided);
    $stmt_meeting->bindParam(':campaign_requirements',$campaign_requirements);
    $stmt_meeting->bindParam(':campaign_deadline',    $campaign_deadline);
    $stmt_meeting->bindParam(':meeting_id',           $meeting_id, PDO::PARAM_INT);
    $stmt_meeting->bindParam(':host_id',              $host_id,    PDO::PARAM_INT);
    $stmt_meeting->execute();

    $db->commit();

    sendSuccess('체험단이 수정되었습니다', [
        'meeting_id'  => $meeting_id,
        'location_id' => $location_id,
    ]);

} catch (PDOException $pdoException) {
    if ($db->inTransaction()) $db->rollBack();
    sendError('DB 오류: ' . $pdoException->getMessage(), 500);
}
?>