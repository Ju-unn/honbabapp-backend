<?php
/**
 * 채팅 이미지 업로드 API
 * POST /api/chat/upload_chat_image.php
 *
 * Android 에서 갤러리/카메라로 가져온 이미지를
 * Multipart 로 업로드 → S3 chat/ 폴더 → URL 반환
 *
 * 이 API 는 S3 업로드와 URL 반환만 담당.
 *    chat_messages INSERT 는 하지 않음.
 *    → 클라이언트가 받은 URL 을 WebSocket message 이벤트로 보내면
 *    → websocket/server.js 에서 단일 진실의 원천으로 INSERT
 *    (텍스트 메시지와 동일 흐름 → 일관성 보장)
 *
 * 요청 (Multipart/form-data):
 *   chat_image: 이미지 파일
 *   room_id   : 채팅방 ID
 *   user_id   : 업로드하는 유저 ID (멤버 권한 검증용)
 *
 * 응답:
 * {
 *   "success": true,
 *   "message": "채팅 이미지 업로드 완료",
 *   "data": {
 *     "image_url": "https://honbabapp-images.s3.ap-northeast-2.amazonaws.com/chat/chat_5_7_xxx.jpg",
 *     "file_name": "chat_5_7_1234567890_abcd1234.jpg",
 *     "file_size": 204800,
 *     "mime_type": "image/jpeg"
 *   }
 * }
 */

 require_once '../config/database.php';
 require_once '../config/s3_config.php';
 require_once '../utils/response.php';
 require_once '../utils/jwt.php';
 
 
 header('Access-Control-Allow-Origin: *');
 header('Access-Control-Allow-Methods: POST, OPTIONS');
 header('Access-Control-Allow-Headers: Content-Type');
 
 if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
     exit(0);
 }
 
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     sendError('POST 요청만 허용됩니다', 405);
 }
 
 // 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
 $user_id = get_user_id_from_token();


// ── 검증 설정 ──────────────────────────────────────────
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMimeTypes  = ['image/jpeg', 'image/png', 'image/webp'];
$maxFileSize       = 10 * 1024 * 1024; // 10MB (채팅 이미지는 모임 이미지보다 큰 한도)


try {
    // 1. 필수 파라미터 검증
    if (!isset($_FILES['chat_image'])) {
        sendError('이미지 파일이 없습니다 (파라미터명: chat_image)');
    }

    if (!isset($_POST['room_id'])) {
        sendError('room_id는 필수입니다');
    }

    $room_id = intval($_POST['room_id']);

    if ($room_id <= 0) {
        sendError('유효하지 않은 room_id');
    }

    $file = $_FILES['chat_image'];

    // 2. 업로드 에러 체크
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE   => '파일 크기가 서버 설정을 초과했습니다',
            UPLOAD_ERR_FORM_SIZE  => '파일 크기가 너무 큽니다',
            UPLOAD_ERR_PARTIAL    => '파일이 부분적으로만 업로드되었습니다',
            UPLOAD_ERR_NO_FILE    => '파일이 업로드되지 않았습니다',
            UPLOAD_ERR_NO_TMP_DIR => '임시 디렉토리가 없습니다',
            UPLOAD_ERR_CANT_WRITE => '디스크에 쓰기 실패했습니다',
        ];
        $errorMsg = $errorMessages[$file['error']] ?? '알 수 없는 업로드 오류';
        sendError($errorMsg);
    }

    // 3. 파일 크기 검증
    if ($file['size'] > $maxFileSize) {
        sendError('이미지 크기는 10MB 이하여야 합니다');
    }

    $originalFileSize = $file['size'];

    // 4. 확장자 검증
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions)) {
        sendError('지원하지 않는 이미지 형식입니다 (JPG, PNG, WEBP만 가능)');
    }

    // 5. MIME 타입 검증 (실제 파일 내용 기반 — 확장자 위조 방어)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimeTypes)) {
        sendError('올바른 이미지 파일이 아닙니다.');
    }

    // 6. 채팅방 멤버 권한 검증
    //    이게 빠지면 비멤버도 임의의 room_id 로 S3 에 이미지 업로드 가능
    $database = new Database();
    $db = $database->getConnection();
    $stmt = $db->prepare(
        'SELECT 1 FROM chat_room_members WHERE room_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$room_id, $user_id]);
    if (!$stmt->fetch()) {
        sendError('해당 채팅방의 멤버가 아닙니다', 403);
    }

    // 7. 고유한 파일명 생성
    //    형식: chat_{room_id}_{user_id}_{timestamp}_{random8}.{ext}
    //    → S3 객체 키만 봐도 어느 방, 누가, 언제 올렸는지 즉시 파악 가능
    $timestamp    = time();
    $randomString = bin2hex(random_bytes(4)); // 8자 hex
    $newFileName  = "chat_{$room_id}_{$user_id}_{$timestamp}_{$randomString}.{$fileExtension}";

    // 8. S3 업로드 (chat/ 폴더)
    $s3Config = new S3Config();
    $s3Url = $s3Config->uploadChatImage($file['tmp_name'], $newFileName, $mimeType);

    // 9. 성공 응답
    // chat_messages INSERT 는 여기서 하지 않음
    sendSuccess('채팅 이미지 업로드 완료', [
        'image_url' => $s3Url,
        'file_name' => $newFileName,
        'file_size' => $originalFileSize,
        'mime_type' => $mimeType,
    ]);

} catch (Exception $e) {
    error_log('채팅 이미지 업로드 오류: ' . $e->getMessage());
    sendError('채팅 이미지 업로드 실패: ' . $e->getMessage(), 500);
}
?>