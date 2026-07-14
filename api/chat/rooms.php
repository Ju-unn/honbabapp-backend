<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

// 본인 확인 (쿼리스트링의 user_id를 신뢰하지 않음 — 위조 방지)
$user_id = get_user_id_from_token();

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // 내가 속한 채팅방 목록 + 안읽은 수 계산
    $stmt = $db->prepare("
        SELECT
            cr.room_id,
            cr.room_type,
            cr.room_name,
            cr.room_image,
            cr.meeting_id,
            cr.owner_id,
            cr.last_message,
            cr.last_msg_time,
            (
                SELECT COUNT(*)
                FROM chat_messages cm
                WHERE cm.room_id = cr.room_id
                AND (cm.sender_id != :uid1 OR cm.sender_id IS NULL)
                AND cm.created_at > COALESCE(crm.last_read_at, '1970-01-01')
            ) as unread_count
        FROM chat_rooms cr
        INNER JOIN chat_room_members crm
            ON cr.room_id = crm.room_id
            AND crm.user_id = :uid2
        ORDER BY cr.last_msg_time DESC
    ");
    $stmt->execute([':uid1' => $user_id, ':uid2' => $user_id]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rooms)) {
        // 방 ID 목록 추출
        $roomIds = array_column($rooms, 'room_id');
        $inClause = implode(',', array_map('intval', $roomIds));

        // 변경: IN절로 전체 참여자 한 번에 조회 후 PHP에서 그룹핑
        $stmt2 = $db->query("
            SELECT crm.room_id, u.user_id, u.user_nickname, u.user_profile_image, crm.last_read_at
            FROM chat_room_members crm
            INNER JOIN users u ON crm.user_id = u.user_id
            WHERE crm.room_id IN ($inClause)
        ");
        $allParticipants = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // room_id 기준으로 그룹핑
        $participantsMap = [];
        foreach ($allParticipants as $p) {
            $participantsMap[$p['room_id']][] = $p;
        }

        // rooms에 참여자 매핑 + 숫자 타입 변환
        foreach ($rooms as &$room) {
            $room['participants'] = $participantsMap[$room['room_id']] ?? [];
            $room['room_id']      = intval($room['room_id']);
            $room['owner_id']     = intval($room['owner_id']);
            $room['unread_count'] = intval($room['unread_count']);
            $room['meeting_id']   = $room['meeting_id'] ? intval($room['meeting_id']) : null;

            // direct 방 이름은 저장된 스냅샷 대신 동적으로 계산
            // - 상대방이 있으면: 현재 닉네임 (닉네임 변경 자동 반영)
            // - 상대방이 없으면: 탈퇴 시 chat_room_members에서 삭제된 경우 → "탈퇴한 유저"
            if ($room['room_type'] === 'direct') {
                $other_nickname = null;
                foreach ($room['participants'] as $p) {
                    if (intval($p['user_id']) !== intval($user_id)) {
                        $other_nickname = $p['user_nickname'];
                        break;
                    }
                }
                $room['room_name'] = ($other_nickname !== null && $other_nickname !== '')
                    ? $other_nickname
                    : '탈퇴한 유저';
            }
        }
    }

    sendSuccess('채팅방 목록 조회 성공', $rooms);

} catch (PDOException $e) {
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>