<?php
/**
 * 받은 후기 목록 조회 API  [신규]
 * GET /api/review/received.php?user_id=X
 *
 * ChatRoomParticipantDetailActivity 에서 해당 참여자 프로필 진입 시 호출.
 * 익명 표시 방침에 따라 reviewer 관련 컬럼은 응답에 포함하지 않는다.
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "받은 후기 목록 조회 성공",
 *   "data": {
 *     "reviews": [
 *       {
 *         "review_id": 12,
 *         "rating": 4,
 *         "content": "좋았어요",        // nullable
 *         "created_at": "2026-06-24 19:00:00"
 *       }
 *     ]
 *   }
 * }
 *
 * 응답 (reviews 없음):
 * {
 *   "success": true,
 *   "message": "받은 후기 목록 조회 성공",
 *   "data": { "reviews": [] }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

if (empty($_GET['user_id'])) {
    sendError('user_id는 필수입니다');
}

$target_user_id = intval($_GET['user_id']);

if ($target_user_id <= 0) {
    sendError('유효하지 않은 user_id입니다');
}

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // ─────────────────────────────────────────────
    // 받은 후기 조회
    //
    // - reviewee_id = :user_id 인 행 전체
    // - 최신순(created_at DESC) 정렬
    // - 익명 방침: reviewer_id 등 작성자 정보는 SELECT 하지 않음
    // ─────────────────────────────────────────────
    $review_query = "SELECT
                         review_id,
                         rating,
                         content,
                         created_at
                       FROM meeting_reviews
                      WHERE reviewee_id = :user_id
                      ORDER BY created_at DESC";

    $review_stmt = $db->prepare($review_query);
    $review_stmt->bindParam(':user_id', $target_user_id, PDO::PARAM_INT);
    $review_stmt->execute();

    $reviews = [];
    while ($row = $review_stmt->fetch(PDO::FETCH_ASSOC)) {
        $reviews[] = [
            'review_id'  => intval($row['review_id']),
            'rating'     => intval($row['rating']),
            'content'    => $row['content'],          // null 허용
            'created_at' => $row['created_at'],
        ];
    }

    sendSuccess('받은 후기 목록 조회 성공', [
        'reviews' => $reviews,
    ]);

} catch (PDOException $error) {
    sendError('받은 후기 조회 중 오류가 발생했습니다: ' . $error->getMessage(), 500);
}
?>