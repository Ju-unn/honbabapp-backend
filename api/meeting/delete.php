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
    // 내가 호스트인 모임이 맞는지 확인 (meeting_image는 S3 삭제용으로 함께 조회)
    $stmt_check = $db->prepare("SELECT meeting_id, meeting_image FROM meetings
                                  WHERE meeting_id = :meeting_id
                                  AND meeting_host_id = :host_id");
    $stmt_check->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':host_id', $host_id, PDO::PARAM_INT);
    $stmt_check->execute();

    $meeting = $stmt_check->fetch(PDO::FETCH_ASSOC);
    if ($meeting === false) {
      sendError('모임을 찾을 수 없거나 삭제 권한이 없습니다');
    }

    // 승인된 타인 참여자가 있으면 삭제 불가 ('대기' 신청자는 함께 삭제됨)
    $stmt_participants = $db->prepare("SELECT COUNT(*) FROM meeting_participants
                                        WHERE meeting_id = :meeting_id
                                        AND user_id != :host_id
                                        AND participation_status = '승인'");
    $stmt_participants->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt_participants->bindParam(':host_id', $host_id, PDO::PARAM_INT);
    $stmt_participants->execute();

    if (intval($stmt_participants->fetchColumn()) > 0) {
        sendError('현재 참여하고 있는 사람이 있습니다');
    }

    // 이 모임의 채팅방 목록 조회
    $stmt_rooms = $db->prepare("SELECT room_id FROM chat_rooms WHERE meeting_id = :meeting_id");
    $stmt_rooms->bindParam(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt_rooms->execute();
    $room_ids = $stmt_rooms->fetchAll(PDO::FETCH_COLUMN);

    // S3에서 지울 이미지 URL 수집 (DB 삭제 전에 조회해 둠)
    // - 모임 대표 이미지: meetings.meeting_image
    // - 채팅 사진: chat_messages.image_url(구버전 단일) / image_urls(신규 JSON 배열)
    $s3_urls = [];
    if (!empty($meeting['meeting_image'])) {
        $s3_urls[] = $meeting['meeting_image'];
    }

    if (!empty($room_ids)) {
        $placeholders = implode(',', array_fill(0, count($room_ids), '?'));
        $stmt_images = $db->prepare("SELECT image_url, image_urls FROM chat_messages
                                      WHERE room_id IN ($placeholders)
                                      AND (image_url IS NOT NULL OR image_urls IS NOT NULL)");
        $stmt_images->execute($room_ids);

        while ($row = $stmt_images->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['image_urls'])) {
                $decoded = json_decode($row['image_urls'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $url) {
                        if (!empty($url)) $s3_urls[] = $url;
                    }
                }
            } elseif (!empty($row['image_url'])) {
                $s3_urls[] = $row['image_url'];
            }
        }
    }

    $db->beginTransaction();

    // 채팅방 연쇄 삭제 (FK 순서 중요)
    // chat_message_reads → chat_messages → chat_room_members → chat_rooms
    if (!empty($room_ids)) {
        $placeholders = implode(',', array_fill(0, count($room_ids), '?'));

        $stmt = $db->prepare("DELETE cmr FROM chat_message_reads cmr
                               INNER JOIN chat_messages cm ON cmr.message_id = cm.message_id
                               WHERE cm.room_id IN ($placeholders)");
        $stmt->execute($room_ids);

        $stmt = $db->prepare("DELETE FROM chat_messages WHERE room_id IN ($placeholders)");
        $stmt->execute($room_ids);

        $stmt = $db->prepare("DELETE FROM chat_room_members WHERE room_id IN ($placeholders)");
        $stmt->execute($room_ids);

        $stmt = $db->prepare("DELETE FROM chat_rooms WHERE room_id IN ($placeholders)");
        $stmt->execute($room_ids);
    }

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

    // S3 이미지 삭제 — DB 커밋 이후 실행. 실패해도 모임 삭제 자체는 성공 처리 (파일 잔존은 저장 비용뿐)
    if (!empty($s3_urls)) {
        try {
            require_once '../config/s3_config.php';
            $s3 = new S3Config();
            foreach (array_unique($s3_urls) as $url) {
                // 자체 버킷 URL만 삭제 (외부 이미지 URL이 저장된 경우 스킵)
                if (strpos($url, 'honbabapp-images') !== false) {
                    $s3->deleteProfileImage($url);
                }
            }
        } catch (Exception $e) {
            error_log('모임 삭제 후 S3 이미지 정리 실패 (meeting_id=' . $meeting_id . '): ' . $e->getMessage());
        }
    }

    sendSuccess('모임이 삭제되었습니다');
} catch (PDOException $e) {
  if ($db->inTransaction()) {
      $db->rollBack();
  }
  sendError('모임 삭제 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
}

?>