<?php
/**
 * 모임 / 체험단 생성 API
 * POST /api/meeting/create.php
 *
 * 변경사항 (체험단 도입):
 * - meeting_type 수신 (기본값 '일반')
 * - campaign 4개 컬럼 INSERT
 * - 체험단일 때 campaign_channels, campaign_provided 필수 검증
 * - 인원 상한: 일반 2~10, 체험단 2~20
 * - 호스트 자동참여 / 트랜잭션 기존 그대로
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// ── 공통 필수 필드 ───────────────────────────────────────────
if (empty($input['user_id']))              sendError('user_id가 필요합니다');
if (empty($input['meeting_title']))        sendError('모임 제목이 필요합니다');
if (empty($input['meeting_category']))     sendError('카테고리가 필요합니다');
if (empty($input['meeting_date']))         sendError('모임 날짜가 필요합니다');
if (empty($input['meeting_max_participants'])) sendError('최대 참여 인원이 필요합니다');
if (empty($input['location']))             sendError('위치 정보가 필요합니다');

$user_id              = intval($input['user_id']);
$meeting_title        = trim($input['meeting_title']);
$meeting_description  = isset($input['meeting_description']) ? trim($input['meeting_description']) : '';
$meeting_category     = trim($input['meeting_category']);
$meeting_date         = trim($input['meeting_date']);
$meeting_max_participants = intval($input['meeting_max_participants']);
$meeting_price_range  = isset($input['meeting_price_range']) ? trim($input['meeting_price_range']) : null;
$meeting_image        = isset($input['meeting_image']) ? trim($input['meeting_image']) : null;
$location             = $input['location'];

// ── 체험단 전용 필드 ─────────────────────────────────────────
$meeting_type            = isset($input['meeting_type']) ? trim($input['meeting_type']) : '일반';
$campaign_channels       = isset($input['campaign_channels'])    ? trim($input['campaign_channels'])    : null;
$campaign_provided       = isset($input['campaign_provided'])    ? trim($input['campaign_provided'])    : null;
$campaign_requirements   = isset($input['campaign_requirements']) ? trim($input['campaign_requirements']) : null;
$campaign_deadline       = isset($input['campaign_deadline'])    ? trim($input['campaign_deadline'])    : null;

// ── 타입 유효성 검사 ─────────────────────────────────────────
if (!in_array($meeting_type, ['일반', '체험단'])) {
    sendError('meeting_type은 일반 또는 체험단이어야 합니다');
}

// ── 인원 상한 검사 (일반 2~10 / 체험단 2~20) ────────────────
$max_participants_limit = ($meeting_type === '체험단') ? 20 : 10;
if ($meeting_max_participants < 2 || $meeting_max_participants > $max_participants_limit) {
    sendError("최대 참여 인원은 2~{$max_participants_limit}명 사이여야 합니다");
}

// ── 체험단 필수 필드 검증 ────────────────────────────────────
if ($meeting_type === '체험단') {
    if (empty($campaign_channels)) {
        sendError('체험단은 홍보 채널을 선택해야 합니다');
    }
    if (empty($campaign_provided)) {
        sendError('체험단은 제공 내역을 입력해야 합니다');
    }
}

// ── 일반 모임이면 campaign 필드 null 강제 ───────────────────
if ($meeting_type === '일반') {
    $campaign_channels     = null;
    $campaign_provided     = null;
    $campaign_requirements = null;
    $campaign_deadline     = null;
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $db->beginTransaction();

    // ── 1. locations INSERT ──────────────────────────────────
    $sigungu     = $location['sigungu']      ?? null;
    $place_name  = $location['place_name']   ?? null;
    $address     = $location['address']      ?? null;
    $road_address = $location['road_address'] ?? null;
    $sido        = $location['sido']         ?? null;
    $dong        = $location['dong']         ?? null;
    $map_x       = $location['map_x']        ?? null;
    $map_y       = $location['map_y']        ?? null;
    $category    = $location['category']     ?? null;
    $phone_number = $location['phone_number'] ?? null;

    $location_insert_query = "INSERT INTO locations (
        place_name, address, road_address, sido, sigungu, dong,
        map_x, map_y, category, phone_number
    ) VALUES (
        :place_name, :address, :road_address, :sido, :sigungu, :dong,
        :map_x, :map_y, :category, :phone_number
    )";

    $location_stmt = $db->prepare($location_insert_query);
    $location_stmt->bindParam(':place_name',   $place_name);
    $location_stmt->bindParam(':address',      $address);
    $location_stmt->bindParam(':road_address', $road_address);
    $location_stmt->bindParam(':sido',         $sido);
    $location_stmt->bindParam(':sigungu',      $sigungu);
    $location_stmt->bindParam(':dong',         $dong);
    $location_stmt->bindParam(':map_x',        $map_x);
    $location_stmt->bindParam(':map_y',        $map_y);
    $location_stmt->bindParam(':category',     $category);
    $location_stmt->bindParam(':phone_number', $phone_number);
    $location_stmt->execute();

    $location_id = $db->lastInsertId();

    // ── 2. meetings INSERT (campaign 컬럼 추가) ──────────────
    $meeting_insert_query = "INSERT INTO meetings (
        meeting_type,
        meeting_host_id, meeting_title, meeting_description, meeting_category,
        meeting_date, meeting_max_participants, meeting_current_participants,
        meeting_price_range, meeting_image, location_id, meeting_status,
        campaign_channels, campaign_provided, campaign_requirements, campaign_deadline
    ) VALUES (
        :meeting_type,
        :host_id, :title, :description, :category,
        :date, :max_participants, 1,
        :price_range, :meeting_image, :location_id, '모집중',
        :campaign_channels, :campaign_provided, :campaign_requirements, :campaign_deadline
    )";

    $meeting_stmt = $db->prepare($meeting_insert_query);
    $meeting_stmt->bindParam(':meeting_type',        $meeting_type);
    $meeting_stmt->bindParam(':host_id',             $user_id);
    $meeting_stmt->bindParam(':title',               $meeting_title);
    $meeting_stmt->bindParam(':description',         $meeting_description);
    $meeting_stmt->bindParam(':category',            $meeting_category);
    $meeting_stmt->bindParam(':date',                $meeting_date);
    $meeting_stmt->bindParam(':max_participants',    $meeting_max_participants);
    $meeting_stmt->bindParam(':price_range',         $meeting_price_range);
    $meeting_stmt->bindParam(':meeting_image',       $meeting_image);
    $meeting_stmt->bindParam(':location_id',         $location_id);
    $meeting_stmt->bindParam(':campaign_channels',   $campaign_channels);
    $meeting_stmt->bindParam(':campaign_provided',   $campaign_provided);
    $meeting_stmt->bindParam(':campaign_requirements', $campaign_requirements);
    $meeting_stmt->bindParam(':campaign_deadline',   $campaign_deadline);
    $meeting_stmt->execute();

    $meeting_id = $db->lastInsertId();

    // ── 3. 호스트 자동참여 (기존 그대로) ────────────────────
    $participant_insert_query = "INSERT INTO meeting_participants (
        meeting_id, user_id, participation_status, approved_at, approved_by
    ) VALUES (
        :meeting_id, :user_id, '승인', NOW(), :approved_by
    )";

    $participant_stmt = $db->prepare($participant_insert_query);
    $participant_stmt->bindParam(':meeting_id',  $meeting_id);
    $participant_stmt->bindParam(':user_id',     $user_id);
    $participant_stmt->bindParam(':approved_by', $user_id);
    $participant_stmt->execute();

    $db->commit();

    sendSuccess('모임이 생성되었습니다', [
        'meeting_id'  => (int)$meeting_id,
        'location_id' => (int)$location_id,
        'meeting_type' => $meeting_type
    ]);

} catch (PDOException $e) {
    $db->rollBack();
    sendError('모임 생성 실패: ' . $e->getMessage(), 500);
}
?>