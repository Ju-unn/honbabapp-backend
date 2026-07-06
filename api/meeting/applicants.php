<?php
/**
 * 신청자 목록 조회 API
 * GET /api/meeting/applicants.php?meeting_id=1&status=대기
 *
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

// 본인 확인 (쿼리스트링의 host_id를 신뢰하지 않음 — 위조 방지)
$host_id = get_user_id_from_token();

if (empty($_GET['meeting_id'])) {
    sendError('meeting_id가 필요합니다');
}

$meeting_id = intval($_GET['meeting_id']);
$status     = isset($_GET['status']) ? trim($_GET['status']) : '대기';

$database = new Database();
$db = $database->getConnection();

try {
    // ── 1. 모임 존재 + 타입 확인 ────────────────────────────
    $meeting_stmt = $db->prepare(
        "SELECT meeting_id, meeting_host_id, meeting_type FROM meetings WHERE meeting_id = :meeting_id"
    );
    $meeting_stmt->bindParam(':meeting_id', $meeting_id);
    $meeting_stmt->execute();

    if ($meeting_stmt->rowCount() === 0) {
        sendError('존재하지 않는 모임입니다', 404);
    }

    $meeting      = $meeting_stmt->fetch(PDO::FETCH_ASSOC);
    $meeting_type = $meeting['meeting_type'];

    // ── 2. 호스트 권한 확인 ───────────────────────────────
    if ($meeting['meeting_host_id'] != $host_id) {
        sendError('호스트만 신청자 목록을 조회할 수 있습니다', 403);
    }

    // ── 3. 신청자 조회 (타입에 따라 필드 분기) ─────────────
    if ($meeting_type === '체험단') {
        // 체험단: SNS ID + 자기소개 포함
        // Issue #6B: AND mp.user_id != :host_id 추가
        $stmt = $db->prepare("
            SELECT
                mp.participant_id,
                mp.user_id,
                mp.participation_status,
                mp.applied_at,
                mp.approved_at,
                mp.applicant_intro,
                u.user_nickname,
                u.user_profile_image,
                u.user_manner_score,
                u.user_instagram_id,
                u.user_blog_id
            FROM meeting_participants mp
            JOIN users u ON mp.user_id = u.user_id
            WHERE mp.meeting_id    = :meeting_id
              AND mp.participation_status = :status
              AND mp.user_id != :host_id
            ORDER BY mp.applied_at ASC
        ");
    } else {
        // 일반 모임: SNS ID 비공개 유지
        // Issue #6B: AND mp.user_id != :host_id 추가
        $stmt = $db->prepare("
            SELECT
                mp.participant_id,
                mp.user_id,
                mp.participation_status,
                mp.applied_at,
                mp.approved_at,
                u.user_nickname,
                u.user_profile_image,
                u.user_manner_score
            FROM meeting_participants mp
            JOIN users u ON mp.user_id = u.user_id
            WHERE mp.meeting_id    = :meeting_id
              AND mp.participation_status = :status
              AND mp.user_id != :host_id
            ORDER BY mp.applied_at ASC
        ");
    }

    $stmt->bindParam(':meeting_id', $meeting_id);
    $stmt->bindParam(':status',     $status);
    $stmt->bindParam(':host_id',    $host_id); 
    $stmt->execute();

    $applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendSuccess('신청자 목록 조회 성공', [
        'meeting_id'   => $meeting_id,
        'meeting_type' => $meeting_type,
        'status'       => $status,
        'total_count'  => count($applicants),
        'applicants'   => $applicants,
    ]);

} catch (PDOException $e) {
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>