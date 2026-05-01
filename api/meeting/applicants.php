<?php
/**
 * 모임 신청자 목록 조회 API (호스트 전용)
 * GET /api/meeting/applicants.php
 * 
 * 쿼리 파라미터:
 * - meeting_id: 모임 ID (필수)
 * - host_id: 호스트 ID (필수, 권한 확인용)
 * - status: "대기", "승인", "거절" (선택, 기본값: "대기")
 * 
 * 예시:
 * /api/meeting/applicants.php?meeting_id=1&host_id=1&status=대기
 * 
 * 응답:
 * {
 *   "success": true,
 *   "message": "신청자 목록 조회 성공",
 *   "data": {
 *     "meeting_id": 1,
 *     "applicants": [
 *       {
 *         "participant_id": 5,
 *         "user_id": 2,
 *         "user_nickname": "카드가든",
 *         "user_profile_image": "https://...",
 *         "user_manner_score": 4.2,
 *         "user_gender": "남성",
 *         "participation_status": "대기",
 *         "applied_at": "2025-12-21 15:30:00"
 *       },
 *       {
 *         "participant_id": 6,
 *         "user_id": 3,
 *         "user_nickname": "홍길동",
 *         "user_profile_image": "https://...",
 *         "user_manner_score": 4.8,
 *         "user_gender": "남성",
 *         "participation_status": "대기",
 *         "applied_at": "2025-12-21 16:00:00"
 *       }
 *     ],
 *     "total_count": 2
 *   }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

// 필수 파라미터 확인
if (empty($_GET['meeting_id']) || empty($_GET['host_id'])) {
    sendError('meeting_id와 host_id는 필수입니다');
}

$meeting_id = intval($_GET['meeting_id']);
$host_id = intval($_GET['host_id']);
$status = isset($_GET['status']) ? trim($_GET['status']) : '대기';

// status 검증
$valid_statuses = ['대기', '승인', '거절', '취소'];
if (!in_array($status, $valid_statuses)) {
    sendError('유효하지 않은 status 값입니다. 가능한 값: ' . implode(', ', $valid_statuses));
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

try {
    // 1. 모임 존재 및 호스트 권한 확인
    $meeting_query = "SELECT meeting_id, meeting_host_id 
                      FROM meetings 
                      WHERE meeting_id = :meeting_id";
    
    $meeting_stmt = $db->prepare($meeting_query);
    $meeting_stmt->bindParam(':meeting_id', $meeting_id);
    $meeting_stmt->execute();
    
    if ($meeting_stmt->rowCount() === 0) {
        sendError('존재하지 않는 모임입니다', 404);
    }
    
    $meeting = $meeting_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. 호스트 권한 확인
    if ($meeting['meeting_host_id'] != $host_id) {
        sendError('호스트만 신청자 목록을 조회할 수 있습니다', 403);
    }
    
    // 3. 신청자 목록 조회
    $query = "SELECT 
                mp.participant_id,
                mp.user_id,
                mp.participation_status,
                mp.applied_at,
                u.user_nickname,
                u.user_profile_image,
                u.user_manner_score,
                u.user_gender
              FROM meeting_participants mp
              JOIN users u ON mp.user_id = u.user_id
              WHERE mp.meeting_id = :meeting_id
              AND mp.participation_status = :status
              ORDER BY mp.applied_at ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':meeting_id', $meeting_id);
    $stmt->bindParam(':status', $status);
    $stmt->execute();
    
    $applicants = [];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $applicants[] = [
            'participant_id' => (int)$row['participant_id'],
            'user_id' => (int)$row['user_id'],
            'user_nickname' => $row['user_nickname'],
            'user_profile_image' => $row['user_profile_image'],
            'user_manner_score' => (float)$row['user_manner_score'],
            'user_gender' => $row['user_gender'],
            'participation_status' => $row['participation_status'],
            'applied_at' => $row['applied_at']
        ];
    }
    
    sendSuccess('신청자 목록 조회 성공', [
        'meeting_id' => $meeting_id,
        'applicants' => $applicants,
        'total_count' => count($applicants)
    ]);
    
} catch(PDOException $e) {
    sendError('신청자 목록 조회 실패: ' . $e->getMessage(), 500);
}
?>