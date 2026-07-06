<?php
/**
 * 채팅방 사진 목록 조회 API
 *
 * GET /api/chat/photos.php?room_id=5&user_id=10&page=1&limit=30
 *
 * Response:
 * {
 *   "success": true,
 *   "message": "사진 조회 성공",
 *   "data": {
 *     "photos": [
 *       {
 *         "message_id": 99,
 *         "image_url": "https://s3.../chat/img1.jpg",
 *         "sender_id": 18,
 *         "sender_name": "테스트10",
 *         "created_at": "2026-05-14 10:00:00"
 *       },
 *       ...
 *     ],
 *     "page": 1,
 *     "limit": 30,
 *     "total_count": 0,
 *     "has_more": false
 *   }
 * }
 *
 * 정렬: 최신 사진이 먼저 (created_at DESC)
 *
 * 멤버가 아닌 사용자가 접근 시도하면 403 응답.
 *
 * 현재 채팅 이미지 업로드 기능은 미구현 상태이므로 항상 빈 배열 반환됨.
 * 추후 chat/upload_chat_image.php 가 추가되면 자연스럽게 데이터가 채워짐.
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
     sendError('GET 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (쿼리스트링의 user_id를 신뢰하지 않음 — 위조 방지)
 $user_id = get_user_id_from_token();
 
 $room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;
 $page    = isset($_GET['page'])    ? intval($_GET['page'])    : 1;
 $limit   = isset($_GET['limit'])   ? intval($_GET['limit'])   : 30;
 
 if ($room_id <= 0) sendError('room_id가 필요합니다');

// 안전 가드 (악의적 큰 값 방지)
if ($page < 1) $page = 1;
if ($limit < 1 || $limit > 100) $limit = 30;

// ───────────────────────────────────────────
// 2) DB 연결
// ───────────────────────────────────────────
$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // ───────────────────────────────────────
    // 3) 권한 체크 — 멤버 확인
    // ───────────────────────────────────────
    $stmt = $db->prepare("
        SELECT id FROM chat_room_members
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$room_id, $user_id]);
    if (!$stmt->fetch()) {
        sendError('채팅방 멤버가 아닙니다', 403);
    }

    // ───────────────────────────────────────
    // 4) 총 사진 개수 (페이지네이션 메타 정보)
    // ───────────────────────────────────────
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM chat_messages
        WHERE room_id = ? AND msg_type = 'image'
    ");
    $stmt->execute([$room_id]);
    $total_count = intval($stmt->fetch(PDO::FETCH_ASSOC)['total']);

    // ───────────────────────────────────────
    // 5) 사진 리스트 조회 (최신순)
    //    - sender 정보 JOIN (닉네임 표시용)
    //    - msg_type='image' 필터링
    // ───────────────────────────────────────
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare("
        SELECT
            cm.message_id,
            cm.image_url,
            cm.image_urls,
            cm.sender_id,
            u.user_nickname AS sender_name,
            cm.created_at
        FROM chat_messages cm
        LEFT JOIN users u ON cm.sender_id = u.user_id
        WHERE cm.room_id = ?
          AND cm.msg_type = 'image'
        ORDER BY cm.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$room_id]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ───────────────────────────────────────
    // 6) image_urls 펼치기 + 타입 변환
    //    묶음 메시지 1개 → 사진 N개 엔트리로 분해
    //    (같은 message_id/sender/created_at 공유, image_url 만 각각)
    // ───────────────────────────────────────

    // ───────────────────────────────────────
    // 6) image_urls 펼치기 + 타입 변환
    //    묶음 메시지 1개 → 사진 N개 엔트리로 분해
    //    (같은 message_id/sender/created_at 공유, image_url 만 각각)
    // ───────────────────────────────────────
    $expanded = [];
    foreach ($photos as $photo) {
        $mid   = intval($photo['message_id']);
        $sid   = $photo['sender_id'] !== null ? intval($photo['sender_id']) : null;
        $sname = $photo['sender_name'];
        $cat   = $photo['created_at'];

        // image_urls 정규화: 신규 묶음 → 배열, 구버전 → 단일 폴백
        $urls = [];
        if (!empty($photo['image_urls'])) {
            $decoded = json_decode($photo['image_urls'], true);
            if (is_array($decoded)) $urls = $decoded;
        }
        if (empty($urls) && !empty($photo['image_url'])) {
            $urls = [$photo['image_url']];
        }

        // 각 URL을 개별 엔트리로 펼침
        foreach ($urls as $url) {
            $expanded[] = [
                'message_id'  => $mid,
                'image_url'   => $url,
                'sender_id'   => $sid,
                'sender_name' => $sname,
                'created_at'  => $cat,
            ];
        }
    }
    $photos = $expanded;
        

    // ───────────────────────────────────────
    // 7) has_more 계산
    //    - 현재 page * limit 이 total 보다 작으면 더 있음
    // ───────────────────────────────────────
    $has_more = ($page * $limit) < $total_count;

    // ───────────────────────────────────────
    // 8) 응답
    // ───────────────────────────────────────
    sendSuccess('사진 조회 성공', [
        'photos'      => $photos,
        'page'        => $page,
        'limit'       => $limit,
        'total_count' => $total_count,
        'has_more'    => $has_more,
    ]);

} catch (PDOException $e) {
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>