<?php
/**
 * 모임 참여 신청 API
 * POST /api/meeting/join.php
 * 
 * 요청:
 * {
 *   "meeting_id": 1,
 *   "user_id": 2
 * }
 * 
 * 응답:
 * {
 *   "success": true,
 *   "message": "모임 참여 신청이 완료되었습니다. 호스트의 승인을 기다려주세요.",
 *   "data": {
 *     "participant_id": 5,
 *     "participation_status": "대기"
 *   }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

// 필수 필드 확인
if (empty($input['meeting_id']) || empty($input['user_id'])) {
    sendError('meeting_id와 user_id는 필수입니다');
}

$meeting_id = intval($input['meeting_id']);
$user_id = intval($input['user_id']);

// DB 연결
$database = new Database();
$db = $database->getConnection();

try {
    // 1. 모임 존재 확인
    $meeting_query = "SELECT 
                        meeting_id,
                        meeting_host_id,
                        meeting_max_participants,
                        meeting_current_participants,
                        meeting_status
                      FROM meetings 
                      WHERE meeting_id = :meeting_id";
    
    $meeting_stmt = $db->prepare($meeting_query);
    $meeting_stmt->bindParam(':meeting_id', $meeting_id);
    $meeting_stmt->execute();
    
    if ($meeting_stmt->rowCount() === 0) {
        sendError('존재하지 않는 모임입니다', 404);
    }
    
    $meeting = $meeting_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. 모집 상태 확인
    if ($meeting['meeting_status'] !== '모집중') {
        sendError('모집이 마감된 모임입니다');
    }
    
    // 3. 호스트 본인 확인
    if ($meeting['meeting_host_id'] == $user_id) {
        sendError('본인이 만든 모임에는 신청할 수 없습니다');
    }
    
    // 4. 정원 확인
    if ($meeting['meeting_current_participants'] >= $meeting['meeting_max_participants']) {
        sendError('모임 정원이 가득 찼습니다');
    }
    
    // 5. 중복 신청 확인
    $duplicate_query = "SELECT participant_id, participation_status 
                        FROM meeting_participants 
                        WHERE meeting_id = :meeting_id 
                        AND user_id = :user_id";
    
    $duplicate_stmt = $db->prepare($duplicate_query);
    $duplicate_stmt->bindParam(':meeting_id', $meeting_id);
    $duplicate_stmt->bindParam(':user_id', $user_id);
    $duplicate_stmt->execute();
    
    if ($duplicate_stmt->rowCount() > 0) {
        $existing = $duplicate_stmt->fetch(PDO::FETCH_ASSOC);
 
        if ($existing['participation_status'] === '대기') {
            sendError('이미 신청한 모임입니다. 호스트의 승인을 기다려주세요.');
        } else if ($existing['participation_status'] === '승인') {
            sendError('이미 참여 중인 모임입니다.');
        } else if ($existing['participation_status'] === '거절') {
            sendError('참여가 거절된 모임입니다.');
        } else if ($existing['participation_status'] === '강퇴') {
            // 강퇴된 유저는 같은 모임에 재신청 불가
            sendError('해당 모임에서 강퇴되어 재신청할 수 없습니다.');
        } else if ($existing['participation_status'] === '취소') {
            // 취소 상태인 경우 재신청 허용
            // 기존 레코드 삭제 후 새로 생성
            $delete_query = "DELETE FROM meeting_participants
                            WHERE participant_id = :participant_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':participant_id', $existing['participant_id']);
            $delete_stmt->execute();
        }
    }
    
    // 6. 참여 신청 생성 (대기 상태)
    $insert_query = "INSERT INTO meeting_participants (
        meeting_id, user_id, participation_status
    ) VALUES (
        :meeting_id, :user_id, '대기'
    )";
    
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':meeting_id', $meeting_id);
    $insert_stmt->bindParam(':user_id', $user_id);
    $insert_stmt->execute();
    
    $participant_id = $db->lastInsertId();
    
    sendSuccess('모임 참여 신청이 완료되었습니다. 호스트의 승인을 기다려주세요.', [
        'participant_id' => (int)$participant_id,
        'participation_status' => '대기'
    ]);
    
} catch(PDOException $e) {
    sendError('모임 참여 신청 실패: ' . $e->getMessage(), 500);
}
?>