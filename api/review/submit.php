<?php
/**
 * 후기 제출 API
 * POST /api/review/submit.php
 *
 * - reviewer 검증: 인증자 집합(QR 찍은 사람) 유지
 * - reviewee 검증: 인증자 집합 → 리뷰 가능 집합(승인된 참여자 + 호스트)으로 변경
 *   → QR 미인증(불참) 상대에게도 낮은 별점으로 노쇼 리뷰를 남길 수 있음
 *
 * 요청:
 * {
 *   "meeting_id": 1,
 *   "reviewer_id": 3,
 *   "reviewee_id": 5,
 *   "rating": 4,
 *   "content": "좋은 시간이었어요!"   // optional, 없거나 빈 값이면 NULL
 * }
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "후기가 등록되었습니다",
 *   "data": {
 *     "review_id": 12,
 *     "reviewee_id": 5,
 *     "rating": 4,
 *     "manner_score_added": 0.8,
 *     "reviewee_new_manner_score": 36.8
 *   }
 * }
 *
 * 검증 순서:
 *   1. 파라미터 형식 (rating 1~5 정수, reviewer != reviewee, content 길이)
 *   2. reviewer: 인증자 집합(QR 인증자)에 속하는지 확인
 *      reviewee: 리뷰 가능 집합(승인된 참여자 + 호스트)에 속하는지 확인
 *   3. 중복 제출 사전 체크
 *   4. 트랜잭션: meeting_reviews INSERT + users.user_manner_score 가산
 * 점수 환산: rating 1~2점은 가산 없음(0.0), 3~5점만 rating * 0.2 → 0.6 / 0.8 / 1.0
 */
 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/push_emit.php';
 require_once '../utils/jwt.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     sendError('POST 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (body의 reviewer_id를 신뢰하지 않음 — 위조 방지)
 // 매너점수를 올리고 내리는 행위라 위조 방지가 특히 중요함
 $reviewer_id = get_user_id_from_token();
 
 $input = json_decode(file_get_contents('php://input'), true);
 if (!$input) {
     sendError('잘못된 요청 데이터');
 }
 
 // ─────────────────────────────────────────────
 // 1. 파라미터 형식 검증
 // ─────────────────────────────────────────────
 $meeting_id  = isset($input['meeting_id'])  ? intval($input['meeting_id'])  : 0;
 $reviewee_id = isset($input['reviewee_id']) ? intval($input['reviewee_id']) : 0;
 $rating      = isset($input['rating'])      ? intval($input['rating'])      : 0;

// content: trim 후 빈 문자열이면 NULL, 500자 초과면 에러
$content = isset($input['content']) ? trim((string)$input['content']) : '';
if ($content === '') {
    $content = null;
} elseif (mb_strlen($content) > 500) {
    sendError('후기 내용은 500자 이하로 작성해주세요');
}

if ($meeting_id <= 0)  sendError('meeting_id가 필요합니다');
if ($reviewer_id <= 0) sendError('reviewer_id가 필요합니다');
if ($reviewee_id <= 0) sendError('reviewee_id가 필요합니다');

if ($rating < 1 || $rating > 5) {
    sendError('rating은 1~5 사이의 정수여야 합니다');
}

if ($reviewer_id === $reviewee_id) {
    sendError('본인에게는 후기를 작성할 수 없습니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // ─────────────────────────────────────────────
    // 2-0. 모임 정보 조회 — QR 존재 여부와 무관하게 항상 필요
    // ─────────────────────────────────────────────
    $meeting_stmt = $db->prepare(
        "SELECT meeting_host_id, meeting_type FROM meetings WHERE meeting_id = :meeting_id"
    );
    $meeting_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $meeting_stmt->execute();

    if ($meeting_stmt->rowCount() === 0) {
        sendError('존재하지 않는 모임입니다', 404);
    }

    $meeting_row  = $meeting_stmt->fetch(PDO::FETCH_ASSOC);
    $host_id      = intval($meeting_row['meeting_host_id']);
    $meeting_type = $meeting_row['meeting_type'];

    // ─────────────────────────────────────────────
    // 2-A. 노쇼 리뷰 여부 판별
    //
    //      체험단에서 호스트가 "승인 후 취소"(campaign_cancel.php로 노쇼
    //      처리된 참여자, approved_at IS NOT NULL)에게 남기는 리뷰는
    //      QR 인증 검증을 건너뛴다 — 노쇼는 애초에 모임에 나타나지 않아
    //      QR을 찍을 기회 자체가 없었으므로 QR 인증자 집합 요구가 성립하지 않음.
    // ─────────────────────────────────────────────
    $is_noshow_review = false;
    if ($reviewer_id === $host_id && $meeting_type === '체험단') {
        $noshow_stmt = $db->prepare(
            "SELECT 1 FROM meeting_participants
              WHERE meeting_id = :meeting_id AND user_id = :reviewee_id
                AND participation_status = '취소' AND approved_at IS NOT NULL"
        );
        $noshow_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
        $noshow_stmt->bindParam(':reviewee_id', $reviewee_id, PDO::PARAM_INT);
        $noshow_stmt->execute();
        $is_noshow_review = $noshow_stmt->rowCount() > 0;
    }

    if (!$is_noshow_review) {
        // ─────────────────────────────────────────────
        // QR 코드 정보 조회 — 인증자 집합 구성용
        //
        //      QR 자체가 없으면 아무도 인증한 사람이 없으므로
        //      reviewer 가 인증자 집합에 속할 수 없음 → 즉시 거부
        // ─────────────────────────────────────────────
        $qr_query = "SELECT qr.qr_id, qr.host_credited
                      FROM meeting_qr_codes qr
                      WHERE qr.meeting_id = :meeting_id";

        $qr_stmt = $db->prepare($qr_query);
        $qr_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
        $qr_stmt->execute();

        if ($qr_stmt->rowCount() === 0) {
            sendError('QR 인증을 하지 않아 후기를 작성할 수 없습니다');
        }

        $qr_info       = $qr_stmt->fetch(PDO::FETCH_ASSOC);
        $qr_id         = intval($qr_info['qr_id']);
        $host_credited = intval($qr_info['host_credited']);

        // ─────────────────────────────────────────────
        // 인증자 집합 구성 (reviewer 검증용)
        //      = meeting_qr_scans(qr_id) user_id 전체
        //        + (host_credited=1 이면 meeting_host_id)
        // ─────────────────────────────────────────────
        $scan_query = "SELECT user_id FROM meeting_qr_scans WHERE qr_id = :qr_id";
        $scan_stmt  = $db->prepare($scan_query);
        $scan_stmt->bindParam(':qr_id', $qr_id, PDO::PARAM_INT);
        $scan_stmt->execute();

        $verified_user_ids = [];
        while ($row = $scan_stmt->fetch(PDO::FETCH_ASSOC)) {
            $verified_user_ids[] = intval($row['user_id']);
        }

        if ($host_credited === 1 && !in_array($host_id, $verified_user_ids, true)) {
            $verified_user_ids[] = $host_id;
        }

        // ─────────────────────────────────────────────
        // reviewer 검증 — QR 인증자여야 후기 작성 가능
        // ─────────────────────────────────────────────
        if (!in_array($reviewer_id, $verified_user_ids, true)) {
            sendError('QR 인증을 하지 않아 후기를 작성할 수 없습니다');
        }
    }

    // ─────────────────────────────────────────────
    // 2-D. 리뷰 가능 집합 구성 (reviewee 검증용)
    //      = 승인된 참여자(meeting_participants) + 호스트
    //
    //      QR 인증 여부와 무관하게, 모임에 승인된 사람이라면
    //      모두 리뷰 대상이 된다. (노쇼/불참자도 포함)
    //
    //      체험단(meeting_type='체험단')에 한해서는, 승인 후 취소한
    //      사람(campaign_cancel.php로 노쇼 처리된 경우, approved_at IS NOT NULL)도
    //      포함한다 — 대기 중 자진 취소(approved_at NULL)는 제외.
    // ─────────────────────────────────────────────
    $participant_query = "SELECT user_id
                            FROM meeting_participants
                           WHERE meeting_id = :meeting_id
                             AND (
                               participation_status = '승인'
                               OR (
                                 :meeting_type = '체험단'
                                 AND participation_status = '취소'
                                 AND approved_at IS NOT NULL
                               )
                             )";

    $participant_stmt = $db->prepare($participant_query);
    $participant_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $participant_stmt->bindParam(':meeting_type', $meeting_type);
    $participant_stmt->execute();

    $reviewable_user_ids = [];
    while ($row = $participant_stmt->fetch(PDO::FETCH_ASSOC)) {
        $reviewable_user_ids[] = intval($row['user_id']);
    }

    // 호스트는 meeting_participants 에 행이 없을 수 있으므로 명시적으로 합집합 처리
    if (!in_array($host_id, $reviewable_user_ids, true)) {
        $reviewable_user_ids[] = $host_id;
    }

    // ─────────────────────────────────────────────
    // 2-E. reviewee 검증 — 리뷰 가능 집합에 속해야 함
    // ─────────────────────────────────────────────
    if (!in_array($reviewee_id, $reviewable_user_ids, true)) {
        sendError('해당 사용자는 이 모임의 리뷰 대상이 아닙니다');
    }

    // ─────────────────────────────────────────────
    // 3. 중복 제출 사전 체크
    //    (트랜잭션 안 UNIQUE 위반으로도 방어되지만,
    //     여기서 먼저 걸러내면 더 친절한 에러 메시지를 줄 수 있음)
    // ─────────────────────────────────────────────
    $dup_query = "SELECT review_id FROM meeting_reviews
                   WHERE meeting_id  = :meeting_id
                     AND reviewer_id = :reviewer_id
                     AND reviewee_id = :reviewee_id";

    $dup_stmt = $db->prepare($dup_query);
    $dup_stmt->bindParam(':meeting_id',  $meeting_id,  PDO::PARAM_INT);
    $dup_stmt->bindParam(':reviewer_id', $reviewer_id, PDO::PARAM_INT);
    $dup_stmt->bindParam(':reviewee_id', $reviewee_id, PDO::PARAM_INT);
    $dup_stmt->execute();

    if ($dup_stmt->rowCount() > 0) {
        sendError('이미 후기를 작성했습니다');
    }

    // ─────────────────────────────────────────────
    // 4. 트랜잭션: meeting_reviews INSERT + 매너점수 가산
    // ─────────────────────────────────────────────
    $db->beginTransaction();

    $insert_query = "INSERT INTO meeting_reviews
                        (meeting_id, reviewer_id, reviewee_id, rating, content, created_at)
                     VALUES
                        (:meeting_id, :reviewer_id, :reviewee_id, :rating, :content, NOW())";

    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':meeting_id',  $meeting_id,  PDO::PARAM_INT);
    $insert_stmt->bindParam(':reviewer_id', $reviewer_id, PDO::PARAM_INT);
    $insert_stmt->bindParam(':reviewee_id', $reviewee_id, PDO::PARAM_INT);
    $insert_stmt->bindParam(':rating',      $rating,      PDO::PARAM_INT);
    $insert_stmt->bindParam(':content', $content, $content === null ?
        PDO::PARAM_NULL : PDO::PARAM_STR);

    try {
        $insert_stmt->execute();
    } catch (PDOException $duplicate_error) {
        // SQLSTATE 23000 = UNIQUE 제약(uq_review) 위반
        // → 동시 요청으로 레이스 발생 시 여기서 최종 방어
        $db->rollBack();
        if ($duplicate_error->getCode() === '23000') {
            sendError('이미 후기를 작성했습니다');
        }
        throw $duplicate_error;
    }

    $review_id = intval($db->lastInsertId());

    // 매너점수 누적 가산: rating 3~5점만 가산(rating * 0.2), 1~2점(낮은 평가)은 가산 없음(0.0)
    // — 낮은 별점이 오히려 점수를 올려버리는 걸 방지 (노쇼 리뷰 등 벌점 의도가 있는 경우 대비)
    $manner_score_added = $rating <= 2 ? 0.0 : $rating * 0.2;

    // 상한 100 고정. 하한(0)은 페널티 쪽에서 GREATEST(0, ...)로 이미 막아뒀으니 대칭으로 처리.
    // (컬럼은 decimal(5,2)로 확장 필요 — decimal(3,2)는 최댓값 9.99라 100 자체를 저장 못 함)
    $update_query = "UPDATE users
                        SET user_manner_score = LEAST(100, user_manner_score + :score)
                      WHERE user_id = :reviewee_id";

    $update_stmt = $db->prepare($update_query);
    // bindValue 사용: bindParam(참조 바인딩)은 execute 시점에 변수를
    // 문자열로 재해석할 수 있어, 응답에 쓸 $manner_score_added 의
    // 타입이 의도치 않게 바뀌는 문제가 있음 → 값으로만 전달
    $update_stmt->bindValue(':score',      $manner_score_added);
    $update_stmt->bindValue(':reviewee_id', $reviewee_id, PDO::PARAM_INT);
    $update_stmt->execute();

    // 가산 후 점수 재조회 (응답용)
    $score_query = "SELECT user_manner_score FROM users WHERE user_id = :reviewee_id";
    $score_stmt  = $db->prepare($score_query);
    $score_stmt->bindParam(':reviewee_id', $reviewee_id, PDO::PARAM_INT);
    $score_stmt->execute();
    $reviewee_new_manner_score = (float)$score_stmt->fetchColumn();

    $db->commit();

    // ─────────────────────────────────────────────
    // 5. 푸시 알림: reviewee 에게 "새 후기" 알림 발송 (fire-and-forget)
    //    reviewer 닉네임은 알림 문구에만 쓰이므로, 푸시 발송 실패가
    //    후기 등록 자체(이미 commit 완료)에는 영향을 주지 않는다.
    // ─────────────────────────────────────────────
    $reviewer_nickname_stmt = $db->prepare(
        "SELECT user_nickname FROM users WHERE user_id = :reviewer_id"
    );
    $reviewer_nickname_stmt->bindParam(':reviewer_id', $reviewer_id, PDO::PARAM_INT);
    $reviewer_nickname_stmt->execute();
    $reviewer_nickname = (string)$reviewer_nickname_stmt->fetchColumn();

    push_to_user($reviewee_id, 'review_received', '새 후기',
        $reviewer_nickname . '님이 후기를 남겼어요', [
            'meeting_id' => $meeting_id,
        ]
    );

    sendSuccess('후기가 등록되었습니다', [
        'review_id'                 => $review_id,
        'reviewee_id'               => $reviewee_id,
        'rating'                    => $rating,
        'manner_score_added'        => (float)$manner_score_added,
        'reviewee_new_manner_score' => $reviewee_new_manner_score,
    ]);

} catch (PDOException $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('후기 등록 중 오류가 발생했습니다: ' . $error->getMessage(), 500);
}
?>