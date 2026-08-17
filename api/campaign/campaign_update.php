<?php
/**
 * 체험단 수정 API
 * POST /api/campaign/update.php
 *
 * 기존 api/meeting/update.php 구조 기반에서
 * campaign 4개 필드(channels, provided, requirements, deadline) 추가
 *
 * 수정 내역:
 * - 체험단 모집 마감일(campaign_deadline)을 원본 등록일
 *   (meeting_created_at) 기준 최대 14일로 제한
 *
 * 요청:
 * {
 *   "meeting_id": 1,
 *   "user_id": 1,
 *   "meeting_title": "수정된 체험단 제목",
 *   "meeting_description": "수정된 설명",
 *   "meeting_category": "한식",
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

// ── 공통 필수 필드 확인 ──────────────────────────────────────
$required_fields = [
    'meeting_id', 'meeting_title', 'meeting_description',
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
if (empty($input['campaign_deadline'])) {
    sendError('campaign_deadline 필드는 필수입니다');
}

// ── 입력값 정리 ──────────────────────────────────────────────
$meeting_id = intval($input['meeting_id']);
$meeting_title            = trim($input['meeting_title']);
$meeting_description      = trim($input['meeting_description']);
$meeting_category         = trim($input['meeting_category']);

// campaign 전용 필드
$campaign_channels     = trim($input['campaign_channels']);
$campaign_provided     = trim($input['campaign_provided']);
$campaign_requirements = isset($input['campaign_requirements']) && !empty(trim($input['campaign_requirements']))
    ? trim($input['campaign_requirements'])
    : null;
$campaign_deadline = trim($input['campaign_deadline']);

$meeting_max_participants = intval($input['meeting_max_participants']);
$meeting_price_range      = trim($input['meeting_price_range']);
$meeting_image            = isset($input['meeting_image']) && !empty(trim($input['meeting_image']))
    ? trim($input['meeting_image'])
    : null;

// ── 유효성 검증 ──────────────────────────────────────────────
if (mb_strlen($meeting_title) < 2 || mb_strlen($meeting_title) > 50) {
    sendError('체험단 제목은 2~50자 이내로 입력해주세요');
}

if (mb_strlen($meeting_description) < 10 || mb_strlen($meeting_description) > 500) {
    sendError('체험단 설명은 10~500자 이내로 입력해주세요');
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
    //     (14일 제한의 기준점인 meeting_created_at도 함께 조회)
    $stmt_check = $db->prepare("
        SELECT meeting_current_participants, meeting_status, meeting_type, meeting_created_at,
               (meeting_date <= NOW()) AS is_expired
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

    // 마감일(meeting_date = campaign_deadline)이 지난 체험단은 수정 불가
    // (meeting/update.php의 만료 차단과 동일한 정책)
    if (intval($meeting_row['is_expired']) === 1) {
        sendError('이미 종료된 체험단이라 수정할 수 없습니다');
    }

    // 최대 인원이 현재 참여자 수보다 작으면 안 됨
    if ($meeting_max_participants < $meeting_row['meeting_current_participants']) {
        sendError('최대 참여 인원은 현재 참여자 수(' . $meeting_row['meeting_current_participants'] . '명)보다 작을 수 없습니다');
    }

    // meeting_created_at은 DB가 NOW()로 직접 생성하는, 클라이언트가 조작할 수 없는
    // 신뢰 가능한 값이어야 한다. 파싱에 실패한다는 것은 데이터 정합성 문제이지,
    // "오늘 기준으로 새로 14일을 열어줘도 되는" 상황이 아니다.
    // 실패 시 요청을 거부한다(fail-closed) — "오늘 기준 폴백"은 14일 제한 자체를
    // 무력화하는 구멍이 되므로 절대 사용하지 않는다.
    $original_registration_timestamp = strtotime($meeting_row['meeting_created_at']);
    if ($original_registration_timestamp === false) {
        sendError('모임 등록일 정보를 확인할 수 없어 마감일을 처리할 수 없습니다. 관리자에게 문의해주세요', 500);
    }
    // 14일 제한은 날짜 기준: 등록일로부터 14일째 되는 날까지(그 날의 끝까지) 허용
    $campaign_deadline_maximum_timestamp = strtotime(
        date('Y-m-d', $original_registration_timestamp + (14 * 24 * 60 * 60)) . ' 23:59:59'
    );

    $campaign_deadline_timestamp = strtotime($campaign_deadline);
    if ($campaign_deadline_timestamp === false) {
        sendError('모집 마감일 형식이 올바르지 않습니다');
    }

    // 마감일은 그 날의 끝(23:59:59)으로 정규화한다.
    // 00:00:00으로 저장하면 meeting_date(=마감일)가 자정 직후부터 만료 처리되어
    // 마감일 당일에 목록에서 사라지고 신청도 막힌다.
    // (기존 00:00:00으로 저장된 체험단도 수정 시 여기서 보정된다)
    $campaign_deadline = date('Y-m-d', $campaign_deadline_timestamp) . ' 23:59:59';
    $campaign_deadline_timestamp = strtotime($campaign_deadline);

    if ($campaign_deadline_timestamp > $campaign_deadline_maximum_timestamp) {
        sendError('모집 마감일은 등록일로부터 최대 14일 이내로 설정해주세요');
    }
    $meeting_date = $campaign_deadline;

    $db->beginTransaction();

    // ── 요청으로 들어온 sido 값 (NULL/빈 문자열일 수 있음) ──────
    $incoming_sido = isset($location['sido']) ? trim($location['sido']) : null;
    if ($incoming_sido === '') {
        $incoming_sido = null;
    }

    // ── 2. location: place_name + sigungu 로 기존 행 재사용 또는 신규 INSERT ──
    //    (조회 조건은 그대로 두어, 같은 가게가 sido 유무로 중복 생성되지 않게 한다)
    $location_check_stmt = $db->prepare("
        SELECT location_id, sido FROM locations
        WHERE place_name = :place_name
          AND sigungu    = :sigungu
        LIMIT 1
    ");
    $location_check_stmt->bindParam(':place_name', $location['place_name']);
    $location_check_stmt->bindParam(':sigungu',    $location['sigungu']);
    $location_check_stmt->execute();

    if ($location_check_stmt->rowCount() > 0) {
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
        $address      = $location['address']      ?? null;
        $road_address = $location['road_address']  ?? null;
        $sido         = $incoming_sido;
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

    // ── 4. 정원 변경에 따른 모집 상태 재계산 (meeting/update.php와 동일) ──
    //    - 모집완료였는데 최대인원을 늘려 자리가 생기면 → 모집중 복귀
    //    - 모집중인데 최대인원을 현재 인원까지 줄이면 → 모집완료
    //    취소 등 다른 상태는 건드리지 않는다
    $current_participants = intval($meeting_row['meeting_current_participants']);

    if ($meeting_row['meeting_status'] === '모집완료'
        && $meeting_max_participants > $current_participants) {
        $db->prepare("UPDATE meetings SET meeting_status = '모집중' WHERE meeting_id = :meeting_id")
           ->execute([':meeting_id' => $meeting_id]);
    } elseif ($meeting_row['meeting_status'] === '모집중'
        && $meeting_max_participants <= $current_participants) {
        $db->prepare("UPDATE meetings SET meeting_status = '모집완료' WHERE meeting_id = :meeting_id")
           ->execute([':meeting_id' => $meeting_id]);
    }

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