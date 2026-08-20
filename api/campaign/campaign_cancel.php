<?php
/**
 * 체험단 참가자 자기 취소 API
 * POST /api/campaign/campaign_cancel.php
 *
 * 체험단(meeting_type='체험단')은 승인되어도 그룹 채팅방이 생기지 않고
 * 1:1 채팅방만 사용하므로(approve.php 참고), api/chat/leave.php의
 * 그룹 나가기/페널티 로직을 전혀 타지 않는다. 이 API가 체험단 참가자의
 * 유일한 자기 취소 경로다.
 *
 * 요청:
 * { "meeting_id": 1 }
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "신청이 취소되었습니다",
 *   "data": {
 *     "participant_id": 12,
 *     "participation_status": "취소",
 *     "penalty_applied": 5.0,
 *     "new_manner_score": 28.0,
 *     "meeting_current_participants": 3
 *   }
 * }
 *
 * 정책:
 *  - 대기 상태에서 취소 → 페널티 없음 (아직 확정 전이므로)
 *  - 승인 상태에서 취소 → 매너점수 -5.0 고정 차감 (날짜와 무관, 하한 0)
 *  - 호스트 본인은 이 API로 취소 불가 (체험단 삭제는 meeting/delete.php 사용)
 *  - 일반 모임(meeting_type='일반')은 대상 아님 → api/chat/leave.php 사용
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/push_emit.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 본인 확인 (body를 신뢰하지 않음 — 위조 방지)
$user_id = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendError('잘못된 요청 데이터');
}

$meeting_id = isset($input['meeting_id']) ? intval($input['meeting_id']) : 0;
if ($meeting_id <= 0) {
    sendError('meeting_id가 필요합니다');
}

// 승인 후 취소 시 고정 차감폭
const CAMPAIGN_CANCEL_PENALTY = 5.0;

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $db->beginTransaction();

    // [LOCK] 신청 정보 + 모임 정보 (FOR UPDATE로 동시 취소/승인 방지)
    $stmt = $db->prepare("
        SELECT mp.participant_id, mp.participation_status,
               m.meeting_host_id, m.meeting_type, m.meeting_status,
               m.meeting_current_participants, m.meeting_max_participants
        FROM meeting_participants mp
        JOIN meetings m ON m.meeting_id = mp.meeting_id
        WHERE mp.meeting_id = :meeting_id AND mp.user_id = :user_id
        FOR UPDATE
    ");
    $stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        sendError('신청 정보를 찾을 수 없습니다', 404);
    }

    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($participant['meeting_type'] !== '체험단') {
        $db->rollBack();
        sendError('체험단 모임만 이 API로 취소할 수 있습니다');
    }

    $host_id = intval($participant['meeting_host_id']);
    if ($host_id === $user_id) {
        $db->rollBack();
        sendError('호스트는 이 API로 취소할 수 없습니다');
    }

    $status = $participant['participation_status'];
    if ($status !== '대기' && $status !== '승인') {
        $db->rollBack();
        sendError("이미 처리된 신청입니다 (현재 상태: {$status})");
    }

    $participant_id = intval($participant['participant_id']);

    $stmt = $db->prepare("
        UPDATE meeting_participants
        SET participation_status = '취소'
        WHERE participant_id = :participant_id
    ");
    $stmt->bindParam(':participant_id', $participant_id, PDO::PARAM_INT);
    $stmt->execute();

    $penalty = 0.0;
    $new_current_participants = intval($participant['meeting_current_participants']);

    if ($status === '승인') {
        // 대기 상태는 애초에 meeting_current_participants에 포함되지 않으므로
        // (approve.php의 승인 시점에만 +1) 승인 취소일 때만 인원/페널티 처리
        $penalty = CAMPAIGN_CANCEL_PENALTY;

        $stmt = $db->prepare("
            UPDATE users
            SET user_manner_score = GREATEST(0, user_manner_score - :penalty)
            WHERE user_id = :user_id
        ");
        $stmt->bindValue(':penalty', $penalty);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $new_current_participants = max(0, $new_current_participants - 1);
        $stmt = $db->prepare("
            UPDATE meetings
            SET meeting_current_participants = :current
            WHERE meeting_id = :meeting_id
        ");
        $stmt->bindParam(':current', $new_current_participants, PDO::PARAM_INT);
        $stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
        $stmt->execute();

        if ($participant['meeting_status'] === '모집완료'
            && $new_current_participants < intval($participant['meeting_max_participants'])) {
            $stmt = $db->prepare("UPDATE meetings SET meeting_status = '모집중' WHERE meeting_id = :meeting_id");
            $stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    // 차감 후 매너점수 재조회 (응답용)
    $stmt = $db->prepare("SELECT user_manner_score FROM users WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $new_manner_score = (float)$stmt->fetchColumn();

    $db->commit();

    // 호스트에게 푸시 알림 (fire-and-forget, 실패해도 취소 자체는 이미 commit됨)
    $nickname_stmt = $db->prepare("SELECT user_nickname FROM users WHERE user_id = :user_id");
    $nickname_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $nickname_stmt->execute();
    $nickname = (string)$nickname_stmt->fetchColumn();

    push_to_user($host_id, 'campaign_participant_cancelled', '체험단 신청 취소',
        $nickname . '님이 신청을 취소했어요', ['meeting_id' => $meeting_id]);

    sendSuccess('신청이 취소되었습니다', [
        'participant_id'                => $participant_id,
        'participation_status'          => '취소',
        'penalty_applied'                => (float)$penalty,
        'new_manner_score'               => $new_manner_score,
        'meeting_current_participants'   => $new_current_participants,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>
