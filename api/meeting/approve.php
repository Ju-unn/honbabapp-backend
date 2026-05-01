<?php
/**
 * 모임 신청 승인/거절 API
 * POST /api/meeting/approve.php
 * 
 * 요청:
 * {
 *   "participant_id": 5,
 *   "action": "approve",  // "approve" 또는 "reject"
 *   "host_id": 1
 * }
 * 
 * 승인 응답:
 * {
 *   "success": true,
 *   "message": "참여 신청을 승인했습니다",
 *   "data": {
 *     "participant_id": 5,
 *     "participation_status": "승인",
 *     "meeting_current_participants": 3
 *   }
 * }
 * 
 * 거절 응답:
 * {
 *   "success": true,
 *   "message": "참여 신청을 거절했습니다",
 *   "data": {
 *     "participant_id": 5,
 *     "participation_status": "거절"
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
if (empty($input['participant_id']) || empty($input['action']) || empty($input['host_id'])) {
    sendError('participant_id, action, host_id는 필수입니다');
}

$participant_id = intval($input['participant_id']);
$action = trim($input['action']);
$host_id = intval($input['host_id']);

// action 검증
if (!in_array($action, ['approve', 'reject'])) {
    sendError('action은 "approve" 또는 "reject"만 가능합니다');
}

// DB 연결
$database = new Database();
$db = $database->getConnection();

try {
    // 트랜잭션 시작
    $db->beginTransaction();
    
    // 1. 참여 신청 정보 조회
    $query = "SELECT 
                mp.participant_id,
                mp.meeting_id,
                mp.user_id,
                mp.participation_status,
                m.meeting_host_id,
                m.meeting_max_participants,
                m.meeting_current_participants
              FROM meeting_participants mp
              JOIN meetings m ON mp.meeting_id = m.meeting_id
              WHERE mp.participant_id = :participant_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':participant_id', $participant_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        sendError('존재하지 않는 참여 신청입니다', 404);
    }
    
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. 호스트 권한 확인
    if ($participant['meeting_host_id'] != $host_id) {
        $db->rollBack();
        sendError('호스트만 승인/거절할 수 있습니다', 403);
    }
    
    // 3. 상태 확인
    if ($participant['participation_status'] !== '대기') {
        $db->rollBack();
        $current_status = $participant['participation_status'];
        sendError("이미 처리된 신청입니다 (현재 상태: {$current_status})");
    }
    
    // 4. 승인인 경우 정원 확인
    if ($action === 'approve') {
        if ($participant['meeting_current_participants'] >= $participant['meeting_max_participants']) {
            $db->rollBack();
            sendError('모임 정원이 가득 찼습니다');
        }
    }
    
    // 5. 상태 업데이트
    $new_status = ($action === 'approve') ? '승인' : '거절';
    
    $update_query = "UPDATE meeting_participants 
                     SET 
                        participation_status = :status,
                        approved_at = NOW(),
                        approved_by = :approved_by
                     WHERE participant_id = :participant_id";
    
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':status', $new_status);
    $update_stmt->bindParam(':approved_by', $host_id);
    $update_stmt->bindParam(':participant_id', $participant_id);
    $update_stmt->execute();
    
    // 6. 승인인 경우 모임 현재 인원 증가
    $meeting_current_participants = $participant['meeting_current_participants'];
    
    if ($action === 'approve') {
        $update_meeting_query = "UPDATE meetings 
                                 SET meeting_current_participants = meeting_current_participants + 1
                                 WHERE meeting_id = :meeting_id";
        
        $update_meeting_stmt = $db->prepare($update_meeting_query);
        $update_meeting_stmt->bindParam(':meeting_id', $participant['meeting_id']);
        $update_meeting_stmt->execute();
        
        $meeting_current_participants += 1;
        
        // 정원이 가득 찬 경우 모집 완료로 변경
        if ($meeting_current_participants >= $participant['meeting_max_participants']) {
            $complete_query = "UPDATE meetings 
                              SET meeting_status = '모집완료'
                              WHERE meeting_id = :meeting_id";
            
            $complete_stmt = $db->prepare($complete_query);
            $complete_stmt->bindParam(':meeting_id', $participant['meeting_id']);
            $complete_stmt->execute();
        }
    }
    
    // 트랜잭션 커밋
    $db->commit();
    
    $message = ($action === 'approve') ? '참여 신청을 승인했습니다' : '참여 신청을 거절했습니다';
    
    $response_data = [
        'participant_id' => (int)$participant_id,
        'participation_status' => $new_status
    ];
    
    if ($action === 'approve') {
        $response_data['meeting_current_participants'] = (int)$meeting_current_participants;
    }
    
    sendSuccess($message, $response_data);
    
} catch(PDOException $e) {
    // 트랜잭션 롤백
    $db->rollBack();
    sendError('승인/거절 처리 실패: ' . $e->getMessage(), 500);
}
?>