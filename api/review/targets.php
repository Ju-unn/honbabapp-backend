<?php
/**
 * 후기 대상 목록 조회 API
 * GET /api/review/targets.php
 *
 * 채팅방의 "후기작성" 버튼을 눌렀을 때 호출.
 *
 *
 * 요청:
 * GET /api/review/targets.php?meeting_id=1&user_id=3
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "후기 대상 목록 조회 성공",
 *   "data": {
 *     "is_eligible": true,
 *     "targets": [
 *       {
 *         "user_id": 5,
 *         "user_nickname": "김철수",
 *         "user_profile_image": "https://...",
 *         "already_reviewed": false
 *       }
 *     ]
 *   }
 * }
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
     sendError('GET 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (쿼리스트링의 user_id를 신뢰하지 않음 — 위조 방지)
 $request_user_id = get_user_id_from_token();
 
 // 필수 파라미터 확인
 if (empty($_GET['meeting_id'])) {
     sendError('meeting_id는 필수입니다');
 }
 
 $meeting_id = intval($_GET['meeting_id']);

// DB 연결
$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // ─────────────────────────────────────────────
    // 1. 모임의 QR 코드 정보 조회 (qr_id, host_credited, meeting_host_id)
    //
    //    QR이 아직 생성되지 않았다면 인증자 집합이 존재할 수 없음
    //    → is_eligible=false, targets=[] 로 즉시 응답
    // ─────────────────────────────────────────────
    $qr_query = "SELECT qr.qr_id, qr.host_credited, m.meeting_host_id
                  FROM meeting_qr_codes qr
                  JOIN meetings m ON m.meeting_id = qr.meeting_id
                  WHERE qr.meeting_id = :meeting_id";

    $qr_stmt = $db->prepare($qr_query);
    $qr_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $qr_stmt->execute();

    if ($qr_stmt->rowCount() === 0) {
        sendSuccess('후기 대상 목록 조회 성공', [
            'is_eligible' => false,
            'targets'     => [],
        ]);
    }

    $qr_info       = $qr_stmt->fetch(PDO::FETCH_ASSOC);
    $qr_id         = intval($qr_info['qr_id']);
    $host_credited = intval($qr_info['host_credited']);
    $host_id       = intval($qr_info['meeting_host_id']);

    // ─────────────────────────────────────────────
    // 2. 인증자 집합 구성 (is_eligible 판정에만 사용)
    //    - meeting_qr_scans 에 기록된 user_id 전체
    //    - host_credited = 1 이면 호스트도 인증자 집합에 포함
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
    // 3. 요청자가 인증자 집합에 속하는지 확인
    // ─────────────────────────────────────────────
    $is_eligible = in_array($request_user_id, $verified_user_ids, true);

    if (!$is_eligible) {
        sendSuccess('후기 대상 목록 조회 성공', [
            'is_eligible' => false,
            'targets'     => [],
        ]);
    }

    // ─────────────────────────────────────────────
    // 4. 리뷰 대상 전체 집합 구성
    //    = 승인된 참여자(meeting_participants) + 호스트(meeting_host_id)
    //      − 요청자 본인
    //
    //    QR 인증 여부는 이 단계에서 고려하지 않음.
    //    불참한(QR 미인증) 사람도 승인 참여자라면 리뷰 대상이 됨.
    // ─────────────────────────────────────────────
    $participant_query = "SELECT user_id
                            FROM meeting_participants
                           WHERE meeting_id = :meeting_id
                             AND participation_status = '승인'";

    $participant_stmt = $db->prepare($participant_query);
    $participant_stmt->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $participant_stmt->execute();

    $reviewable_user_ids = [];
    while ($row = $participant_stmt->fetch(PDO::FETCH_ASSOC)) {
        $reviewable_user_ids[] = intval($row['user_id']);
    }

    // 호스트는 meeting_participants 에 행이 없을 수 있으므로 명시적으로 합집합 처리
    if (!in_array($host_id, $reviewable_user_ids, true)) {
        $reviewable_user_ids[] = $host_id;
    }

    // 요청자 본인 제외
    $target_ids = array_values(array_diff($reviewable_user_ids, [$request_user_id]));

    // ─────────────────────────────────────────────
    // 5. target_ids → users 조인으로 닉네임/프로필,
    //    meeting_reviews LEFT JOIN 으로 already_reviewed 판단
    // ─────────────────────────────────────────────
    $targets = [];

    if (!empty($target_ids)) {
        // IN 절에 들어갈 placeholder 동적 생성 (?, ?, ?, ...)
        $placeholders = implode(',', array_fill(0, count($target_ids), '?'));

        $target_query = "
            SELECT
                u.user_id,
                u.user_nickname,
                u.user_profile_image,
                CASE WHEN mr.review_id IS NULL THEN 0 ELSE 1 END AS already_reviewed
            FROM users u
            LEFT JOIN meeting_reviews mr
                ON mr.meeting_id = ?
               AND mr.reviewer_id = ?
               AND mr.reviewee_id = u.user_id
            WHERE u.user_id IN ($placeholders)
        ";

        // 파라미터 순서: meeting_id, reviewer_id(요청자), 그 다음 target_ids 전체
        $params = array_merge([$meeting_id, $request_user_id], $target_ids);

        $target_stmt = $db->prepare($target_query);
        $target_stmt->execute($params);

        while ($row = $target_stmt->fetch(PDO::FETCH_ASSOC)) {
            $targets[] = [
                'user_id'            => intval($row['user_id']),
                'user_nickname'      => $row['user_nickname'],
                'user_profile_image' => $row['user_profile_image'],
                'already_reviewed'   => (bool)intval($row['already_reviewed']),
            ];
        }
    }

    sendSuccess('후기 대상 목록 조회 성공', [
        'is_eligible' => true,
        'targets'     => $targets,
    ]);

} catch (PDOException $error) {
    sendError('후기 대상 조회 중 오류가 발생했습니다: ' . $error->getMessage(), 500);
}
?>