<?php
/**
 * 체험단 생성 API
 * POST /api/campaign/campaign_create.php
 *
 * - user_id는 JWT 토큰에서 추출한다 (등록권 위조 방지)
 * - 체험단 등록 시 등록권(user_campaign_tickets) 1개를 차감한다
 * - 모집 마감일(campaign_deadline)은 등록일 기준 최대 14일로 제한된다
 * - 최대 참여 인원은 2~20명이다
 * - meeting_date는 campaign_deadline 값을 그대로 사용한다 (없으면 14일 후 기본값)
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';
require_once '../meeting/meeting_create_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$authenticatedUserId = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);

// ── 공통 필수 필드 ───────────────────────────────────────────
if (empty($input['meeting_title']))            sendError('모임 제목이 필요합니다');
if (empty($input['meeting_category']))         sendError('카테고리가 필요합니다');
if (empty($input['meeting_max_participants'])) sendError('최대 참여 인원이 필요합니다');
if (empty($input['location']))                 sendError('위치 정보가 필요합니다');

$hostUserId             = $authenticatedUserId;
$meetingTitle           = trim($input['meeting_title']);
$meetingDescription     = isset($input['meeting_description']) ? trim($input['meeting_description']) : '';
$meetingCategory        = trim($input['meeting_category']);
$meetingMaxParticipants = intval($input['meeting_max_participants']);
$meetingPriceRange      = isset($input['meeting_price_range']) ? trim($input['meeting_price_range']) : null;
$meetingImage           = isset($input['meeting_image']) ? trim($input['meeting_image']) : null;
$location               = $input['location'];

// ── 체험단 전용 필드 ─────────────────────────────────────────
$campaignChannels     = isset($input['campaign_channels'])     ? trim($input['campaign_channels'])     : null;
$campaignProvided     = isset($input['campaign_provided'])     ? trim($input['campaign_provided'])     : null;
$campaignRequirements = isset($input['campaign_requirements']) ? trim($input['campaign_requirements']) : null;
$campaignDeadline     = isset($input['campaign_deadline'])     ? trim($input['campaign_deadline'])     : null;

// ── 체험단 필수 필드 검증 ────────────────────────────────────
if (empty($campaignChannels)) sendError('체험단은 홍보 플랫폼을 선택해야 합니다');
if (empty($campaignProvided)) sendError('체험단은 제공 내역을 입력해야 합니다');
if (empty($campaignDeadline)) sendError('모집 마감일을 선택해주세요');

// ── 제목·설명 길이 검증 (campaign_update.php와 동일 기준) ────
if (mb_strlen($meetingTitle) < 2 || mb_strlen($meetingTitle) > 50) {
    sendError('체험단 제목은 2~50자 이내로 입력해주세요');
}

if (mb_strlen($meetingDescription) < 10 || mb_strlen($meetingDescription) > 500) {
    sendError('체험단 설명은 10~500자 이내로 입력해주세요');
}

// ── 인원 상한 검사 (체험단: 2~20명) ──────────────────────────
if ($meetingMaxParticipants < 2 || $meetingMaxParticipants > 20) {
    sendError('최대 참여 인원은 2~20명 사이여야 합니다');
}

// 모집 마감일은 필수값이다 
if (empty($campaignDeadline)) {
    sendError('모집 마감일을 선택해주세요');
}

$campaignDeadlineTimestamp = strtotime($campaignDeadline);
if ($campaignDeadlineTimestamp === false) {
    sendError('모집 마감일 형식이 올바르지 않습니다');
}

// 마감일은 그 날의 끝(23:59:59)으로 정규화한다.
// 00:00:00으로 저장하면 meeting_date(=마감일)가 자정 직후부터 만료 처리되어
// 마감일 당일에 목록에서 사라지고 신청도 막힌다.
$campaignDeadline = date('Y-m-d', $campaignDeadlineTimestamp) . ' 23:59:59';
$campaignDeadlineTimestamp = strtotime($campaignDeadline);

// 주의: strtotime()/date()는 서버 PHP 프로세스의 타임존을 기준으로 계산된다.
// database.php의 DB 세션 타임존(KST, +9:00)과 일치하는지 배포 환경에서 확인할 것.
// 14일 제한은 날짜 기준: 등록일로부터 14일째 되는 날까지(그 날의 끝까지) 허용
$campaignDeadlineMaximumTimestamp = strtotime(date('Y-m-d', strtotime('+14 days')) . ' 23:59:59');
if ($campaignDeadlineTimestamp > $campaignDeadlineMaximumTimestamp) {
    sendError('모집 마감일은 등록일로부터 최대 14일 이내로 설정해주세요');
}

$meetingDate = $campaignDeadline;

$database = new Database();
$databaseConnection = $database->getConnection();

if ($databaseConnection === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

// ── rate limit: 대량 생성 스크립트 백스톱 (등록권 차감/트랜잭션 전에 검사) ──
if (isMeetingCreateRateLimited($databaseConnection, $hostUserId)) {
    sendError('요청이 너무 잦습니다. 잠시 후 다시 시도해주세요', 429);
}

try {
    $databaseConnection->beginTransaction();

    // ── 0. 체험단 등록권 확인 및 차감 ────────────────────────
    // "NO_TICKET" 문자열은 클라이언트가 정확히 비교하는 값이므로
    // 이후 더 친절한 한글 문구로 바꾸지 않는다.
    $lockTicketBalanceStatement = $databaseConnection->prepare("
        SELECT ticket_balance FROM user_campaign_tickets
        WHERE user_id = :user_id
        FOR UPDATE
    ");
    $lockTicketBalanceStatement->bindParam(':user_id', $hostUserId, PDO::PARAM_INT);
    $lockTicketBalanceStatement->execute();

    $currentTicketBalance = 0;
    if ($lockTicketBalanceStatement->rowCount() > 0) {
        $ticketRow = $lockTicketBalanceStatement->fetch(PDO::FETCH_ASSOC);
        $currentTicketBalance = (int)$ticketRow['ticket_balance'];
    }

    if ($currentTicketBalance < 1) {
        $databaseConnection->rollBack();
        sendError('NO_TICKET');
    }

    $decreaseTicketBalanceStatement = $databaseConnection->prepare("
        UPDATE user_campaign_tickets
        SET ticket_balance = ticket_balance - 1
        WHERE user_id = :user_id
    ");
    $decreaseTicketBalanceStatement->bindParam(':user_id', $hostUserId, PDO::PARAM_INT);
    $decreaseTicketBalanceStatement->execute();

    // ── 1. locations INSERT ──────────────────────────────────
    $locationId = insertLocation($databaseConnection, $location);

    // ── 2. meetings INSERT ───────────────────────────────────
    $meetingInsertQuery = "INSERT INTO meetings (
        meeting_type,
        meeting_host_id, meeting_title, meeting_description, meeting_category,
        meeting_date, meeting_max_participants, meeting_current_participants,
        meeting_price_range, meeting_image, location_id, meeting_status,
        campaign_channels, campaign_provided, campaign_requirements, campaign_deadline
    ) VALUES (
        '체험단',
        :host_id, :title, :description, :category,
        :date, :max_participants, 1,
        :price_range, :meeting_image, :location_id, '모집중',
        :campaign_channels, :campaign_provided, :campaign_requirements, :campaign_deadline
    )";

    $meetingStatement = $databaseConnection->prepare($meetingInsertQuery);
    $meetingStatement->bindParam(':host_id',               $hostUserId,             PDO::PARAM_INT);
    $meetingStatement->bindParam(':title',                 $meetingTitle);
    $meetingStatement->bindParam(':description',           $meetingDescription);
    $meetingStatement->bindParam(':category',              $meetingCategory);
    $meetingStatement->bindParam(':date',                  $meetingDate);
    $meetingStatement->bindParam(':max_participants',      $meetingMaxParticipants, PDO::PARAM_INT);
    $meetingStatement->bindParam(':price_range',           $meetingPriceRange);
    $meetingStatement->bindParam(':meeting_image',         $meetingImage);
    $meetingStatement->bindParam(':location_id',           $locationId,             PDO::PARAM_INT);
    $meetingStatement->bindParam(':campaign_channels',     $campaignChannels);
    $meetingStatement->bindParam(':campaign_provided',     $campaignProvided);
    $meetingStatement->bindParam(':campaign_requirements', $campaignRequirements);
    $meetingStatement->bindParam(':campaign_deadline',     $campaignDeadline);
    $meetingStatement->execute();

    $meetingId = (int)$databaseConnection->lastInsertId();

    // ── 3. 호스트 자동참여 ───────────────────────────────────
    insertHostParticipant($databaseConnection, $meetingId, $hostUserId);

    $databaseConnection->commit();

    sendSuccess('체험단이 생성되었습니다', [
        'meeting_id'   => $meetingId,
        'location_id'  => $locationId,
        'meeting_type' => '체험단',
    ]);

} catch (PDOException $exception) {
    if ($databaseConnection->inTransaction()) {
        $databaseConnection->rollBack();
    }
    sendError('체험단 생성 실패: ' . $exception->getMessage(), 500);
}
?>