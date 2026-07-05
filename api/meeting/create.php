<?php
/**
 * 모임 / 체험단 생성 API
 * POST /api/meeting/create.php
 *
 * 수정 내역:
 * - meeting_date 필수 검증 제거 (체험단은 채팅으로 날짜 조율)
 * - 일반 모임은 meeting_date 여전히 필수
 * - meeting_date nullable 처리
 * - user_id를 클라이언트 입력이 아니라 JWT 토큰에서 추출하도록 변경
 *   (체험단 등록권 차감 로직이 들어가면서 위조 방지가 필수가 됨)
 * - 체험단 등록 시 등록권(user_campaign_tickets) 1개 차감
 * - 체험단 모집 마감일(campaign_deadline)을 등록일 기준 최대 14일로 제한
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$authenticated_user_id = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);

// ── 공통 필수 필드 ───────────────────────────────────────────
if (empty($input['meeting_title']))            sendError('모임 제목이 필요합니다');
if (empty($input['meeting_category']))         sendError('카테고리가 필요합니다');
if (empty($input['meeting_max_participants'])) sendError('최대 참여 인원이 필요합니다');
if (empty($input['location']))                 sendError('위치 정보가 필요합니다');

$user_id              = $authenticated_user_id;
$meeting_title        = trim($input['meeting_title']);
$meeting_description  = isset($input['meeting_description']) ? trim($input['meeting_description']) : '';
$meeting_category     = trim($input['meeting_category']);
$meeting_max_participants = intval($input['meeting_max_participants']);
$meeting_price_range  = isset($input['meeting_price_range']) ? trim($input['meeting_price_range']) : null;
$meeting_image        = isset($input['meeting_image']) ? trim($input['meeting_image']) : null;
$location             = $input['location'];

// ── 체험단 전용 필드 ─────────────────────────────────────────
$meeting_type          = isset($input['meeting_type']) ? trim($input['meeting_type']) : '일반';
$campaign_channels     = isset($input['campaign_channels'])    ? trim($input['campaign_channels'])    : null;
$campaign_provided     = isset($input['campaign_provided'])    ? trim($input['campaign_provided'])    : null;
$campaign_requirements = isset($input['campaign_requirements']) ? trim($input['campaign_requirements']) : null;
$campaign_deadline     = isset($input['campaign_deadline'])    ? trim($input['campaign_deadline'])    : null;

// ── 타입 유효성 검사 ─────────────────────────────────────────
if (!in_array($meeting_type, ['일반', '체험단'])) {
    sendError('meeting_type은 일반 또는 체험단이어야 합니다');
}

// ── 체험단 모집 마감일: 등록일(현재 시각) 기준 최대 14일 제한 ──
// 주의: strtotime()/date()는 서버 PHP 프로세스의 타임존을 기준으로
// 계산된다. database.php의 DB 세션 타임존(KST, +9:00)과 일치하는지
// 배포 환경에서 반드시 확인할 것.
$campaign_deadline_maximum_timestamp = strtotime('+14 days');

if ($meeting_type === '체험단' && !empty($campaign_deadline)) {
    $campaign_deadline_timestamp = strtotime($campaign_deadline);
    if ($campaign_deadline_timestamp === false) {
        sendError('모집 마감일 형식이 올바르지 않습니다');
    }
    if ($campaign_deadline_timestamp > $campaign_deadline_maximum_timestamp) {
        sendError('모집 마감일은 등록일로부터 최대 14일 이내로 설정해주세요');
    }
}

// ── meeting_date 처리 ────────────────────────────────────────
// 일반 모임: meeting_date = 실제 모임 날짜 (필수)
// 체험단:    meeting_date = campaign_deadline (모집 종료 기준)
//            deadline 없으면 등록일 기준 14일 후 기본값
if ($meeting_type === '일반') {
    if (empty($input['meeting_date'])) sendError('모임 날짜가 필요합니다');
    $meeting_date = trim($input['meeting_date']);
} else {
    // 체험단 — campaign_deadline 을 meeting_date 로 사용
    if (!empty($campaign_deadline)) {
        $meeting_date = $campaign_deadline;
    } else {
        // deadline 미입력 시 14일 후를 기본값으로 설정 (14일 상한과 정합성 유지)
        $meeting_date = date('Y-m-d H:i:s', $campaign_deadline_maximum_timestamp);
    }
}

// ── 인원 상한 검사 (일반 2~10 / 체험단 2~20) ────────────────
$max_participants_limit = ($meeting_type === '체험단') ? 20 : 10;
if ($meeting_max_participants < 2 || $meeting_max_participants > $max_participants_limit) {
    sendError("최대 참여 인원은 2~{$max_participants_limit}명 사이여야 합니다");
}

// ── 체험단 필수 필드 검증 ────────────────────────────────────
if ($meeting_type === '체험단') {
    if (empty($campaign_channels)) sendError('체험단은 홍보 채널을 선택해야 합니다');
    if (empty($campaign_provided)) sendError('체험단은 제공 내역을 입력해야 합니다');
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

    // ── 0. 체험단 등록권 확인 및 차감 ────────────────────────
    // "NO_TICKET" 문자열은 클라이언트가 정확히 비교하는 값이므로
    // 이후 더 친절한 한글 문구로 바꾸지 않는다.
    if ($meeting_type === '체험단') {
        $lock_ticket_balance_statement = $db->prepare("
            SELECT ticket_balance FROM user_campaign_tickets
            WHERE user_id = :user_id
            FOR UPDATE
        ");
        $lock_ticket_balance_statement->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $lock_ticket_balance_statement->execute();

        $current_ticket_balance = 0;
        if ($lock_ticket_balance_statement->rowCount() > 0) {
            $ticket_row = $lock_ticket_balance_statement->fetch(PDO::FETCH_ASSOC);
            $current_ticket_balance = (int)$ticket_row['ticket_balance'];
        }

        if ($current_ticket_balance < 1) {
            $db->rollBack();
            sendError('NO_TICKET');
        }

        $decrease_ticket_balance_statement = $db->prepare("
            UPDATE user_campaign_tickets
            SET ticket_balance = ticket_balance - 1
            WHERE user_id = :user_id
        ");
        $decrease_ticket_balance_statement->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $decrease_ticket_balance_statement->execute();
    }

    // ── 1. locations INSERT ──────────────────────────────────
    $sigungu      = $location['sigungu']      ?? null;
    $place_name   = $location['place_name']   ?? null;
    $address      = $location['address']      ?? null;
    $road_address = $location['road_address'] ?? null;
    $sido         = $location['sido']         ?? null;
    $dong         = $location['dong']         ?? null;
    $map_x        = $location['map_x']        ?? null;
    $map_y        = $location['map_y']        ?? null;
    $category     = $location['category']     ?? null;
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

    // ── 2. meetings INSERT ───────────────────────────────────
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
    $meeting_stmt->bindParam(':meeting_type',          $meeting_type);
    $meeting_stmt->bindParam(':host_id',               $user_id);
    $meeting_stmt->bindParam(':title',                 $meeting_title);
    $meeting_stmt->bindParam(':description',           $meeting_description);
    $meeting_stmt->bindParam(':category',              $meeting_category);
    $meeting_stmt->bindParam(':date',                  $meeting_date);
    $meeting_stmt->bindParam(':max_participants',      $meeting_max_participants);
    $meeting_stmt->bindParam(':price_range',           $meeting_price_range);
    $meeting_stmt->bindParam(':meeting_image',         $meeting_image);
    $meeting_stmt->bindParam(':location_id',           $location_id);
    $meeting_stmt->bindParam(':campaign_channels',     $campaign_channels);
    $meeting_stmt->bindParam(':campaign_provided',     $campaign_provided);
    $meeting_stmt->bindParam(':campaign_requirements', $campaign_requirements);
    $meeting_stmt->bindParam(':campaign_deadline',     $campaign_deadline);
    $meeting_stmt->execute();

    $meeting_id = $db->lastInsertId();

    // ── 3. 호스트 자동참여 ───────────────────────────────────
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
        'meeting_id'   => (int)$meeting_id,
        'location_id'  => (int)$location_id,
        'meeting_type' => $meeting_type
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    sendError('모임 생성 실패: ' . $e->getMessage(), 500);
}
?>