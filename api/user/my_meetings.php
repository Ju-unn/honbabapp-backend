<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';
 
// 조회니까 GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}
 
// 본인 확인 (쿼리스트링의 user_id를 신뢰하지 않음 — 위조 방지)
$user_id = get_user_id_from_token();
 
$database = new Database();
$db = $database->getConnection();
 
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $meetings = [];

    // 1. 내가 호스트인 모임
    // meeting_type 컬럼 추가
    $host_query = "SELECT 
                    m.meeting_id,
                    m.meeting_title,
                    m.meeting_date,
                    m.meeting_category,
                    m.meeting_type,
                    m.meeting_image,
                    m.meeting_status,
                    m.meeting_current_participants,
                    m.meeting_max_participants,
                    'host' AS user_role,
                    IF(m.meeting_date < NOW(), 1, 0) AS is_past
                  FROM meetings m
                  WHERE m.meeting_host_id = :user_id
                  ORDER BY m.meeting_date DESC";

    $host_stmt = $db->prepare($host_query);
    $host_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $host_stmt->execute();
    
    while ($row = $host_stmt->fetch(PDO::FETCH_ASSOC)) {
        $meetings[] = formatMeetingRow($row);
    }

    // 내가 참여자인 모임들
    // meeting_type 컬럼 추가 (이슈 #3)
    $participant_query = "SELECT 
                            m.meeting_id,
                            m.meeting_title,
                            m.meeting_date,
                            m.meeting_category,
                            m.meeting_type,
                            m.meeting_image,
                            m.meeting_status,
                            m.meeting_current_participants,
                            m.meeting_max_participants,
                            'participant' AS user_role,
                            IF(m.meeting_date < NOW(), 1, 0) AS is_past
                          FROM meeting_participants mp
                          JOIN meetings m ON mp.meeting_id = m.meeting_id
                          WHERE mp.user_id = :user_id
                            AND mp.participation_status = '승인'
                            AND m.meeting_host_id != :user_id
                          ORDER BY m.meeting_date DESC";
 
    $participant_stmt = $db->prepare($participant_query);
    $participant_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $participant_stmt->execute();
 
    while ($row = $participant_stmt->fetch(PDO::FETCH_ASSOC)) {
        $meetings[] = formatMeetingRow($row);
    }

    // 날짜 기준 최신순 정렬 (host 모임 + participant 모임 합친 후)
    usort($meetings, function($a, $b) {
        return strtotime($b['meeting_date']) - strtotime($a['meeting_date']); // 최신이 앞
    });

	sendSuccess('내 모임 목록 조회 성공', $meetings);
}  catch (PDOException $e) {
    sendError('데이터베이스 오류: ' . $e->getMessage(), 500);
}

function formatMeetingRow($row) {
    return [
        'meeting_id'                   => (int)$row['meeting_id'],
        'meeting_title'                => $row['meeting_title'],
        'meeting_date'                 => $row['meeting_date'],
        'meeting_category'             => $row['meeting_category'],
        'meeting_type'                 => $row['meeting_type'],   // "일반" 또는 "체험단" (이슈 #3 추가)
        'meeting_image'                => $row['meeting_image'],   // S3 URL or null
        'meeting_status'               => $row['meeting_status'],
        'user_role'                    => $row['user_role'],       // "host" or "participant"
        'meeting_current_participants' => (int)$row['meeting_current_participants'],
        'meeting_max_participants'     => (int)$row['meeting_max_participants'],
        'is_past'                      => (bool)$row['is_past'],  // Android에서 탭 분류에 사용
    ];
}
?>