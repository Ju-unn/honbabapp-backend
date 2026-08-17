1<?php
/**
 * 채팅방 멤버(참가자) 목록 조회 API
 *
 * GET /api/chat/members.php?room_id=5
 *
 * chat_room_members 를 "채팅방 참가자"의 단일 기준(source of truth)으로 삼아
 * 실제로 방에 남아있는 멤버만 반환한다.
 * meeting_participants 는 모임 참여 이력/매너점수 계산용 별도 개념이며,
 * 탈퇴 후에도 과거 모임 참여 기록은 보존되므로 채팅방 참가자 화면에는 사용하지 않는다.
 * (탈퇴 시 delete_account.php 가 chat_room_members 는 무조건 삭제하지만
 *  완료된 모임의 meeting_participants 행은 남겨두기 때문에, 그 데이터로 참가자
 *  수/목록을 계산하면 탈퇴자가 계속 노출되는 문제가 있었음)
 *
 * Response:
 * {
 *   "success": true,
 *   "message": "채팅방 멤버 조회 성공",
 *   "data": {
 *     "room_id": 5,
 *     "owner_id": 12,
 *     "participants": [
 *       { "user_id": 12, "user_nickname": "철수", "user_profile_image": "...", "last_read_at": "2026-07-19 10:00:00" },
 *       ...
 *     ]
 *   }
 * }
 *
 * 멤버가 아닌 사용자가 접근 시도하면 403 응답.
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
if ($room_id <= 0) sendError('room_id가 필요합니다');

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    sendError('DB 연결 실패', 500);
}

try {
    // ── 권한 체크 — 멤버 확인 ──
    $stmt = $db->prepare("
        SELECT id FROM chat_room_members
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->execute([$room_id, $user_id]);
    if (!$stmt->fetch()) {
        sendError('채팅방 멤버가 아닙니다', 403);
    }

    // ── 방 소유자 조회 ──
    $stmt = $db->prepare("SELECT owner_id FROM chat_rooms WHERE room_id = ?");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        sendError('존재하지 않는 채팅방입니다', 404);
    }

    // ── 참가자 목록 (chat_room_members 기준) ──
    $stmt = $db->prepare("
        SELECT crm.user_id, u.user_nickname, u.user_profile_image, crm.last_read_at
        FROM chat_room_members crm
        INNER JOIN users u ON crm.user_id = u.user_id
        WHERE crm.room_id = ?
        ORDER BY crm.joined_at ASC
    ");
    $stmt->execute([$room_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($participants as &$p) {
        $p['user_id'] = (int)$p['user_id'];
        if ($p['user_nickname'] === null || $p['user_nickname'] === '') {
            $p['user_nickname'] = '탈퇴한 유저';
        }
    }
    unset($p);

    sendSuccess('채팅방 멤버 조회 성공', [
        'room_id'      => $room_id,
        'owner_id'     => (int)$room['owner_id'],
        'participants' => $participants,
    ]);

} catch (PDOException $e) {
    sendError('DB 오류: ' . $e->getMessage(), 500);
}
?>
