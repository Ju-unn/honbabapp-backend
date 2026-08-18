<?php
/**
 * 모임 / 체험단 상세 조회 API
 * GET /api/meeting/detail.php?meeting_id=1&user_id=2
 *
 * 변경사항 (체험단 도입):
 * - SELECT에 meeting_type + campaign 4개 컬럼 추가
 * - 응답 구조 하위 호환 유지 (일반 모임은 campaign 필드 null)
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

if (empty($_GET['meeting_id'])) {
    sendError('meeting_id가 필요합니다');
}

$meeting_id = intval($_GET['meeting_id']);
$user_id    = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

$database = new Database();
$db = $database->getConnection();

try {
    // ── 모임 기본 정보 + campaign 컬럼 추가 ─────────────────
    $query = "SELECT
                m.*,
                m.meeting_type,
                m.campaign_channels,
                m.campaign_provided,
                m.campaign_requirements,
                m.campaign_deadline,
                l.*,
                u.user_id            AS host_user_id,
                u.user_nickname      AS host_nickname,
                u.user_profile_image AS host_profile_image,
                u.user_manner_score  AS host_manner_score,
                u.user_gender        AS host_gender
              FROM meetings m
              JOIN locations l ON m.location_id    = l.location_id
              JOIN users     u ON m.meeting_host_id = u.user_id
              WHERE m.meeting_id = :meeting_id";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':meeting_id', $meeting_id);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendError('존재하지 않는 모임입니다', 404);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 조회수 증가 (기존 그대로)
    $db->prepare("UPDATE meetings SET meeting_view_count = meeting_view_count + 1 WHERE meeting_id = :meeting_id")
       ->execute([':meeting_id' => $meeting_id]);

    // 참여자 목록 (기존 그대로)
    $participant_query = "SELECT
                            u.user_id,
                            u.user_nickname,
                            u.user_profile_image,
                            u.user_manner_score,
                            mp.applied_at AS joined_at
                          FROM meeting_participants mp
                          JOIN users u ON mp.user_id = u.user_id
                          WHERE mp.meeting_id = :meeting_id
                          AND mp.participation_status = '승인'
                          ORDER BY mp.applied_at ASC";

    $participant_stmt = $db->prepare($participant_query);
    $participant_stmt->bindParam(':meeting_id', $meeting_id);
    $participant_stmt->execute();
    $participants = $participant_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 탈퇴한 참여자 닉네임 폴백 (과거 모임은 탈퇴자의 참여 기록이 보존됨)
    foreach ($participants as &$p) {
        if ($p['user_nickname'] === null || $p['user_nickname'] === '') {
            $p['user_nickname'] = '탈퇴한 유저';
        }
    }
    unset($p);

    // 내 참여 상태 (기존 그대로)
    $my_participation_status = null;
    if ($user_id !== null) {
        $my_status_stmt = $db->prepare(
            "SELECT participation_status FROM meeting_participants
             WHERE meeting_id = :meeting_id AND user_id = :user_id"
        );
        $my_status_stmt->execute([':meeting_id' => $meeting_id, ':user_id' => $user_id]);
        $my_status_row = $my_status_stmt->fetch(PDO::FETCH_ASSOC);
        if ($my_status_row) {
            $my_participation_status = $my_status_row['participation_status'];
        }
    }

    // 대기 중인 신청자 수 (호스트 관리 버튼 배지용)
    $pending_stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM meeting_participants
         WHERE meeting_id = :meeting_id AND participation_status = '대기'"
    );
    $pending_stmt->execute([':meeting_id' => $meeting_id]);
    $pending_applicant_count = (int)$pending_stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    // ── 응답 조립 ────────────────────────────────────────────
    $meeting_data = [
        'meeting_id'                  => (int)$row['meeting_id'],
        'meeting_type'                => $row['meeting_type'],
        'meeting_title'               => $row['meeting_title'],
        'meeting_description'         => $row['meeting_description'],
        'meeting_category'            => $row['meeting_category'],
        'meeting_date'                => $row['meeting_date'],
        'meeting_max_participants'    => (int)$row['meeting_max_participants'],
        'meeting_current_participants' => (int)$row['meeting_current_participants'],
        'meeting_price_range'         => $row['meeting_price_range'],
        'meeting_status'              => $row['meeting_status'],
        'meeting_is_hot'              => (bool)$row['meeting_is_hot'],
        'meeting_view_count'          => (int)$row['meeting_view_count'],
        'meeting_created_at'          => $row['meeting_created_at'],
        'meeting_image'               => $row['meeting_image'],
        // 체험단 전용 (일반 모임은 null)
        'campaign_channels'           => $row['campaign_channels'],
        'campaign_provided'           => $row['campaign_provided'],
        'campaign_requirements'       => $row['campaign_requirements'],
        'campaign_deadline'           => $row['campaign_deadline'],
        'location' => [
            'location_id'  => (int)$row['location_id'],
            'place_name'   => $row['place_name'],
            'sigungu'      => $row['sigungu'],
            'dong'         => $row['dong'],
            'address'      => $row['address'],
            'road_address' => $row['road_address'],
            'map_x'        => $row['map_x'],
            'map_y'        => $row['map_y'],
        ],
        'host' => [
            'user_id'            => (int)$row['host_user_id'],
            // 호스트 탈퇴 시 user_nickname = NULL → "탈퇴한 유저"로 표시
            'user_nickname'      => ($row['host_nickname'] !== null && $row['host_nickname'] !== '')
                                        ? $row['host_nickname'] : '탈퇴한 유저',
            'user_profile_image' => $row['host_profile_image'],
            'user_manner_score'  => (float)$row['host_manner_score'],
            'user_gender'        => $row['host_gender'],
        ],
        'participants'           => $participants,
        'my_participation_status' => $my_participation_status,
        'pending_applicant_count' => $pending_applicant_count,
    ];

    sendSuccess('모임 상세 조회 성공', $meeting_data);

} catch (PDOException $e) {
    sendError('모임 상세 조회 실패: ' . $e->getMessage(), 500);
}
?>