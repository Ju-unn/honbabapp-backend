<?php
/**
 * 내가 쓴 후기 목록 조회 API  [신규]
 * GET /api/review/written.php
 *
 * 프로필 > 내 리뷰 화면의 "내가 쓴 리뷰" 탭에서 호출.
 * 받은 후기(received.php)와 달리 작성자 본인이 자신의 기록을 보는 화면이므로
 * user_id 파라미터를 받지 않고 토큰의 사용자로만 조회한다.
 * (다른 사람이 쓴 후기 목록을 조회해 익명성을 깨는 것을 방지)
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "작성한 후기 목록 조회 성공",
 *   "data": {
 *     "reviews": [
 *       {
 *         "review_id": 12,
 *         "rating": 4,
 *         "content": "좋았어요",        // nullable
 *         "created_at": "2026-06-24 19:00:00",
 *         "meeting_id": 7,
 *         "meeting_title": "강남 파스타 모임",       // nullable (모임 삭제 시)
 *         "reviewee_id": 3,
 *         "reviewee_nickname": "혼밥러",             // nullable (탈퇴 시)
 *         "reviewee_profile_image": "https://..."    // nullable
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

$authenticated_user_id = get_user_id_from_token();

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $review_query = "SELECT
                         r.review_id,
                         r.rating,
                         r.content,
                         r.created_at,
                         r.meeting_id,
                         m.meeting_title,
                         u.user_id AS reviewee_id,
                         u.user_nickname AS reviewee_nickname,
                         u.user_profile_image AS reviewee_profile_image
                       FROM meeting_reviews r
                       LEFT JOIN meetings m ON r.meeting_id = m.meeting_id
                       LEFT JOIN users u ON r.reviewee_id = u.user_id
                      WHERE r.reviewer_id = :user_id
                      ORDER BY r.created_at DESC";

    $review_stmt = $db->prepare($review_query);
    $review_stmt->bindParam(':user_id', $authenticated_user_id, PDO::PARAM_INT);
    $review_stmt->execute();

    $reviews = [];
    while ($row = $review_stmt->fetch(PDO::FETCH_ASSOC)) {
        $reviews[] = [
            'review_id'              => intval($row['review_id']),
            'rating'                 => intval($row['rating']),
            'content'                => $row['content'],          // null 허용
            'created_at'             => $row['created_at'],
            'meeting_id'             => intval($row['meeting_id']),
            'meeting_title'          => $row['meeting_title'],    // null 허용
            'reviewee_id'            => $row['reviewee_id'] !== null ? intval($row['reviewee_id']) : null,
            'reviewee_nickname'      => $row['reviewee_nickname'],
            'reviewee_profile_image' => $row['reviewee_profile_image'],
        ];
    }

    sendSuccess('작성한 후기 목록 조회 성공', [
        'reviews' => $reviews,
    ]);

} catch (PDOException $error) {
    sendError('작성한 후기 조회 중 오류가 발생했습니다: ' . $error->getMessage(), 500);
}
?>
