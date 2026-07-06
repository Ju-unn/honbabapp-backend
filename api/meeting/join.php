<?php
/**
 * 모임 / 체험단 참여 신청 API
 * POST /api/meeting/join.php
 *
 * 변경사항 (체험단 도입):
 * - 체험단인 경우 신청자 users.user_instagram_id / user_blog_id 둘 다 없으면 에러
 * - applicant_intro 수신 → meeting_participants에 저장
 * - 일반 모임은 기존 동작 그대로 (SNS 체크 없음, applicant_intro 무시)
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     sendError('POST 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
 $user_id = get_user_id_from_token();
 
 $input = json_decode(file_get_contents('php://input'), true);
 
 if (empty($input['meeting_id'])) {
     sendError('meeting_id는 필수입니다');
 }
 
 $meeting_id      = intval($input['meeting_id']);
 $applicant_intro = isset($input['applicant_intro']) ? trim($input['applicant_intro']) : null;
 

// applicant_intro 길이 제한
if ($applicant_intro !== null && mb_strlen($applicant_intro) > 500) {
    sendError('자기소개는 500자 이하로 입력해주세요');
}

$database = new Database();
$db = $database->getConnection();

try {
    // ── 1. 모임 존재 + 타입 확인 ────────────────────────────
    $meeting_query = "SELECT
                        meeting_id,
                        meeting_type,
                        meeting_host_id,
                        meeting_max_participants,
                        meeting_current_participants,
                        meeting_status
                      FROM meetings
                      WHERE meeting_id = :meeting_id";

    $meeting_stmt = $db->prepare($meeting_query);
    $meeting_stmt->bindParam(':meeting_id', $meeting_id);
    $meeting_stmt->execute();

    if ($meeting_stmt->rowCount() === 0) {
        sendError('존재하지 않는 모임입니다', 404);
    }

    $meeting = $meeting_stmt->fetch(PDO::FETCH_ASSOC);
    $meeting_type = $meeting['meeting_type'];

    // ── 2. 모집 상태 확인 (기존 그대로) ─────────────────────
    if ($meeting['meeting_status'] !== '모집중') {
        sendError('모집이 마감된 모임입니다');
    }

    // ── 3. 호스트 본인 확인 (기존 그대로) ───────────────────
    if ($meeting['meeting_host_id'] == $user_id) {
        sendError('본인이 만든 모임에는 신청할 수 없습니다');
    }

    // ── 4. 정원 확인 (기존 그대로) ──────────────────────────
    if ($meeting['meeting_current_participants'] >= $meeting['meeting_max_participants']) {
        sendError('모임 정원이 가득 찼습니다');
    }

    // ── 5. 체험단 전용: SNS ID 보유 여부 확인 ───────────────
    if ($meeting_type === '체험단') {
        $sns_query = "SELECT user_instagram_id, user_blog_id FROM users WHERE user_id = :user_id";
        $sns_stmt  = $db->prepare($sns_query);
        $sns_stmt->bindParam(':user_id', $user_id);
        $sns_stmt->execute();
        $user_sns = $sns_stmt->fetch(PDO::FETCH_ASSOC);

        $has_instagram = !empty($user_sns['user_instagram_id']);
        $has_blog      = !empty($user_sns['user_blog_id']);

        if (!$has_instagram && !$has_blog) {
            sendError('체험단 신청을 위해서는 인스타그램 또는 블로그 ID를 먼저 등록해야 합니다. 프로필 설정에서 등록해 주세요.');
        }

        // 체험단 자기소개 필수
        if (empty($applicant_intro)) {
            sendError('체험단 신청 시 자기소개를 입력해야 합니다');
        }
    }

    // ── 6. 중복 신청 확인 (기존 그대로) ─────────────────────
    $duplicate_stmt = $db->prepare(
        "SELECT participant_id, participation_status
         FROM meeting_participants
         WHERE meeting_id = :meeting_id AND user_id = :user_id"
    );
    $duplicate_stmt->bindParam(':meeting_id', $meeting_id);
    $duplicate_stmt->bindParam(':user_id',    $user_id);
    $duplicate_stmt->execute();

    if ($duplicate_stmt->rowCount() > 0) {
        $existing = $duplicate_stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing['participation_status'] === '대기') {
            sendError('이미 신청한 모임입니다. 호스트의 승인을 기다려주세요.');
        } elseif ($existing['participation_status'] === '승인') {
            sendError('이미 참여 중인 모임입니다.');
        } elseif ($existing['participation_status'] === '거절') {
            sendError('참여가 거절된 모임입니다.');
        } elseif ($existing['participation_status'] === '강퇴') {
            sendError('해당 모임에서 강퇴되어 재신청할 수 없습니다.');
        } elseif ($existing['participation_status'] === '취소') {
            // 취소 상태 → 기존 레코드 삭제 후 재신청 허용
            $db->prepare("DELETE FROM meeting_participants WHERE participant_id = :participant_id")
               ->execute([':participant_id' => $existing['participant_id']]);
        }
    }

    // ── 7. 참여 신청 INSERT (applicant_intro 포함) ───────────
    // 일반 모임이면 applicant_intro null 강제
    if ($meeting_type === '일반') {
        $applicant_intro = null;
    }

    $insert_query = "INSERT INTO meeting_participants (
        meeting_id, user_id, participation_status, applicant_intro
    ) VALUES (
        :meeting_id, :user_id, '대기', :applicant_intro
    )";

    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':meeting_id',      $meeting_id);
    $insert_stmt->bindParam(':user_id',         $user_id);
    $insert_stmt->bindParam(':applicant_intro', $applicant_intro);
    $insert_stmt->execute();

    $participant_id = $db->lastInsertId();

    $message = ($meeting_type === '체험단')
        ? '체험단 신청이 완료되었습니다. 호스트의 승인을 기다려주세요.'
        : '모임 참여 신청이 완료되었습니다. 호스트의 승인을 기다려주세요.';

    sendSuccess($message, [
        'participant_id'       => (int)$participant_id,
        'participation_status' => '대기',
        'meeting_type'         => $meeting_type,
    ]);

} catch (PDOException $e) {
    sendError('참여 신청 실패: ' . $e->getMessage(), 500);
}
?>