<?php
/**
 * 일반 모임 생성 API
 * POST /api/meeting/create.php
 *
 *
 * - user_id는 클라이언트 입력이 아니라 JWT 토큰에서 추출한다 (위조 방지)
 * - meeting_date는 필수이며 실제 모임 날짜를 의미한다
 * - 최대 참여 인원은 2~10명으로 고정된다
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';
require_once 'meeting_create_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$authenticatedUserId = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);

// ── 필수 필드 검증 ───────────────────────────────────────────
if (empty($input['meeting_title']))            sendError('모임 제목이 필요합니다');
if (empty($input['meeting_category']))         sendError('카테고리가 필요합니다');
if (empty($input['meeting_date']))             sendError('모임 날짜가 필요합니다');
if (empty($input['meeting_max_participants'])) sendError('최대 참여 인원이 필요합니다');
if (empty($input['location']))                 sendError('위치 정보가 필요합니다');

$hostUserId             = $authenticatedUserId;
$meetingTitle           = trim($input['meeting_title']);
$meetingDescription     = isset($input['meeting_description']) ? trim($input['meeting_description']) : '';
$meetingCategory        = trim($input['meeting_category']);
$meetingDate            = trim($input['meeting_date']);
$meetingMaxParticipants = intval($input['meeting_max_participants']);
$meetingPriceRange      = isset($input['meeting_price_range']) ? trim($input['meeting_price_range']) : null;
$meetingImage           = isset($input['meeting_image']) ? trim($input['meeting_image']) : null;
$location               = $input['location'];

// ── 제목·설명 길이 검증 (update.php와 동일 기준) ─────────────
if (mb_strlen($meetingTitle) < 5 || mb_strlen($meetingTitle) > 30) {
    sendError('모임명은 5-30자 이내로 입력해주세요');
}

if (mb_strlen($meetingDescription) < 10 || mb_strlen($meetingDescription) > 100) {
    sendError('모임 설명은 최소 10~100자 이상 입력해주세요');
}

// ── 인원 상한 검사 (일반 모임: 2~10명) ───────────────────────
if ($meetingMaxParticipants < 2 || $meetingMaxParticipants > 10) {
    sendError('최대 참여 인원은 2~10명 사이여야 합니다');
}

// ── 모임 날짜 검증  ──────────────────
$meetingDateTimestamp = strtotime($meetingDate);
if ($meetingDateTimestamp === false || $meetingDateTimestamp < time()) {
    sendError('모임 날짜는 현재 시간 이후로 설정해주세요');
}

$database = new Database();
$databaseConnection = $database->getConnection();

if ($databaseConnection === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $databaseConnection->beginTransaction();

    // ── 1. locations INSERT ──────────────────────────────────
    $locationId = insertLocation($databaseConnection, $location);

    // ── 2. meetings INSERT ───────────────────────────────────
    $meetingInsertQuery = "INSERT INTO meetings (
        meeting_type,
        meeting_host_id, meeting_title, meeting_description, meeting_category,
        meeting_date, meeting_max_participants, meeting_current_participants,
        meeting_price_range, meeting_image, location_id, meeting_status
    ) VALUES (
        '일반',
        :host_id, :title, :description, :category,
        :date, :max_participants, 1,
        :price_range, :meeting_image, :location_id, '모집중'
    )";

    $meetingStatement = $databaseConnection->prepare($meetingInsertQuery);
    $meetingStatement->bindParam(':host_id',          $hostUserId,             PDO::PARAM_INT);
    $meetingStatement->bindParam(':title',            $meetingTitle);
    $meetingStatement->bindParam(':description',      $meetingDescription);
    $meetingStatement->bindParam(':category',         $meetingCategory);
    $meetingStatement->bindParam(':date',             $meetingDate);
    $meetingStatement->bindParam(':max_participants', $meetingMaxParticipants, PDO::PARAM_INT);
    $meetingStatement->bindParam(':price_range',      $meetingPriceRange);
    $meetingStatement->bindParam(':meeting_image',    $meetingImage);
    $meetingStatement->bindParam(':location_id',      $locationId,             PDO::PARAM_INT);
    $meetingStatement->execute();

    $meetingId = (int)$databaseConnection->lastInsertId();

    // ── 3. 호스트 자동참여 ───────────────────────────────────
    insertHostParticipant($databaseConnection, $meetingId, $hostUserId);

    $databaseConnection->commit();

    sendSuccess('모임이 생성되었습니다', [
        'meeting_id'   => $meetingId,
        'location_id'  => $locationId,
        'meeting_type' => '일반',
    ]);

} catch (PDOException $exception) {
    if ($databaseConnection->inTransaction()) {
        $databaseConnection->rollBack();
    }
    sendError('모임 생성 실패: ' . $exception->getMessage(), 500);
}
?>