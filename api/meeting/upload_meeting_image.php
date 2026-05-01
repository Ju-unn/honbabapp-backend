<?php
/**
 * 모임 이미지 업로드 API
 * POST /api/meeting/upload_meeting_image.php
 *
 * Android 에서 네이버 이미지 검색으로 가져온 사진을
 * OkHttp 로 다운로드 → 임시 파일 → 이 API 로 Multipart 업로드
 *
 * 요청 (Multipart/form-data):
 *   meeting_image: 이미지 파일
 *
 * 응답:
 * {
 *   "success": true,
 *   "message": "모임 이미지 업로드 완료",
 *   "data": {
 *     "image_url": "https://honbabapp-images.s3.ap-northeast-2.amazonaws.com/meetings/meeting_xxx.jpg",
 *     "file_name": "meeting_1234567890_abcd1234.jpg",
 *     "file_size": 204800,
 *     "mime_type": "image/jpeg"
 *   }
 * }
 */


 require_once '../config/database.php';
 require_once '../config/s3_config.php';
 require_once '../utils/response.php';


 header('Access-Control-Allow-Origin: *');
 header('Access-Control-Allow-Methods: POST, OPTIONS');
 header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  sendError('POST 요청만 허용됩니다', 405);
}


// ── 검증 설정 ──────────────────────────────────────────
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMimeTypes  = ['image/jpeg', 'image/png', 'image/webp'];
$maxFileSize       = 5 * 1024 * 1024; // 5MB
 
try {
  // 1. 파일 존재 확인
  
  if (!isset($_FILES['meeting_image'])) {
    sendError('이미지 파일이 없습니다 (파라미터명: meeting_image)');
  }

  $file = $_FILES['meeting_image'];

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
    sendError('이미지 크기는 5MB 이하여야 합니다');
  }

  $originalFileSize = $file['size'];

  // 4. 확장자 검증
  $fileExtension = strtolower(pathinfo($file['name'] , PATHINFO_EXTENSION));
  if (!in_array($fileExtension, $allowedExtensions)) {
    sendError('지원하지 않는 이미지 형식입니다 (JPG , PNG , WEBP만 가능)');
  }

  // 5. MIME 타입 검증
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mimeType = finfo_file($finfo , $file['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mimeType, $allowedMimeTypes)) {
    sendError('올바른 이미지 파일이 아닙니다.');
  }

  // 6. 고유한 파일명 생성
    //    형식: meeting_{timestamp}_{random8}.jpg
    $timestamp = time();
    $randomString = bin2hex(random_bytes(8));
    $newFileName = "meeting_{$timestamp}_{$randomString}.jpg";

    // 7. S3 업로드
    $s3Config = new S3Config();
    $s3Url = $s3Config->uploadMeetingImage($file['tmp_name'], $newFileName , $mimeType);

    // 8. 성공 응답
    sendSuccess('모임 이미지 업로드 완료', [
      'image_url' => $s3Url,
      'file_name' => $newFileName,
      'file_size' => $originalFileSize,
      'mime_type' => $mimeType,
    ]);
} catch (Exception $e) {
  error_log('모임 이미지 업로드 오류: ' . $e->getMessage());
  sendError('모임 이미지 업로드 실패: ' . $e->getMessage(), 500);
}


?>