<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 본인 확인 (body의 host_id를 신뢰하지 않음 — 위조 방지)
$host_id = get_user_id_from_token();

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['meeting_id'])) {
    sendError('meeting_id가 필요합니다');
}

$meeting_id = intval($input['meeting_id']);

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // 모임 조회 + 호스트 검증
    // 내가 호스트인 모임이 맞는지 확인
    $stmt_check = $db->prepare("SELECT meeting_id FROM meetings
                                  WHERE meeting_id = :meeting_id
                                  AND meeting_host_id = :host_id");
    $stmt_check->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':host_id', $host_id, PDO::PARAM_INT);
    $stmt_check->execute();

    if ($stmt_check->rowCount() === 0) {
      sendError('모임을 찾을 수 없거나 삭제 권한이 없습니다');
    }

    $db->beginTransaction();
    
     // 참여자 먼저 삭제 (자식 테이블 먼저)
    $stmt1 = $db->prepare("DELETE FROM meeting_participants 
                            WHERE meeting_id = :meeting_id");
    $stmt1->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt1->execute();
    
    // 모임 삭제
    $stmt2 = $db->prepare("DELETE FROM meetings 
                            WHERE meeting_id = :meeting_id 
                            AND meeting_host_id = :host_id");
    $stmt2->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt2->bindParam(':host_id', $host_id, PDO::PARAM_INT);
    $stmt2->execute();

    $db->commit();
    sendSuccess('모임이 삭제되었습니다');
} catch (PDOException $e) {
  $db->rollBack();
  sendError('모임 삭제 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
}

?>