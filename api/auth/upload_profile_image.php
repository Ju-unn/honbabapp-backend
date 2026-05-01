<?php
/**
 * 프로필 이미지 업로드 API
 * 
 * 기능:
 * 1. 이미지 파일 검증 (확장자, 크기, MIME 타입)
 * 2. 이미지 리사이즈 (최대 800x800)
 * 3. S3 업로드
 * 4. S3 URL 반환

 */

require_once '../config/database.php';
require_once '../config/s3_config.php';
require_once '../utils/response.php';

// CORS 설정 (이미지 업로드는 별도 요청)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

// 이미지 검증 설정
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
$maxFileSize = 5 * 1024 * 1024; // 5MB

// 이미지 리사이즈 설정
$maxWidth = 800;
$maxHeight = 800;

try {
    // 파일 업로드 확인
    if (!isset($_FILES['profile_image'])) {
        sendError('이미지 파일이 없습니다');
    }
    
    $file = $_FILES['profile_image'];
    $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    // 업로드 에러 체크
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE   => '파일 크기가 서버 설정을 초과했습니다',
            UPLOAD_ERR_FORM_SIZE  => '파일 크기가 너무 큽니다',
            UPLOAD_ERR_PARTIAL    => '파일이 부분적으로만 업로드되었습니다',
            UPLOAD_ERR_NO_FILE    => '파일이 업로드되지 않았습니다',
            UPLOAD_ERR_NO_TMP_DIR => '임시 디렉토리가 없습니다',
            UPLOAD_ERR_CANT_WRITE => '디스크에 쓰기 실패했습니다',
        ];
        
        $errorMsg = isset($errorMessages[$file['error']]) 
                    ? $errorMessages[$file['error']] 
                    : '알 수 없는 업로드 오류';
        
        sendError($errorMsg);
    }
    
    // 파일 크기 검증
    if ($file['size'] > $maxFileSize) {
        sendError('이미지 크기는 5MB 이하여야 합니다');
    }

    // 원본 파일 크기 저장
    $originalFileSize = $file['size'];
    
    // 확장자 검증
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions)) {
        sendError('지원하지 않는 이미지 형식입니다 (JPG, PNG, WEBP만 가능)');
    }
    
    // MIME 타입 검증
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimeTypes)) {
        sendError('올바른 이미지 파일이 아닙니다');
    }
    
    // 이미지 리사이즈
    $resizedImagePath = resizeImage($file['tmp_name'], $mimeType, $maxWidth, $maxHeight);
    
    // 고유한 파일명 생성
    $timestamp = time();
    $randomString = bin2hex(random_bytes(8));
    $newFileName = ($userId > 0 ? "user_{$userId}_" : "") . "{$timestamp}_{$randomString}.{$fileExtension}";
    
    // S3 업로드
    $s3Config = new S3Config();
    $s3Url = $s3Config->uploadProfileImage($resizedImagePath, $newFileName, $mimeType);
    
    // 임시 파일 삭제
    @unlink($resizedImagePath);
    
    // 성공 응답
    sendSuccess('이미지 업로드 완료', [
        'profile_image_url' => $s3Url,
        'file_name' => $newFileName,
        'file_size' => $originalFileSize , 
        'mime_type' => $mimeType
    ]);
    
} catch (Exception $e) {
    error_log('이미지 업로드 오류: ' . $e->getMessage());
    sendError('이미지 업로드 실패: ' . $e->getMessage(), 500);
}

function resizeImage($sourcePath , $mimeType , $maxWidth , $maxHeight) {
  // 원본 이미지 로드
  switch ($mimeType) {
    case 'image/jpeg':
        $sourceImage = imagecreatefromjpeg($sourcePath);
        break;
    case 'image/png':
        $sourceImage = imagecreatefrompng($sourcePath);
        break;
    case 'image/webp':
        $sourceImage = imagecreatefromwebp($sourcePath);
        break;
    default:
        throw new Exception('지원하지 않는 이미지 타입');
  }

  if (!$sourceImage) {
    throw new Exception('이미지 로드 실패');
  }

  // 원본 크기 가져오기
  $originalWidth = imagesx($sourceImage);
  $originalHeight = imagesy($sourceImage);

  // 리사이즈 비율 계산
  $ratio = min($maxWidth / $originalWidth , $maxHeight / $originalHeight);

  // 리사이즈가 필요 없으면 원본 반환
  if ($ratio >= 1) {
    imagedestroy($sourceImage);
    return $sourcePath;
  }

  // 새 크기 계산
  $newWidth = (int)($originalWidth * $ratio);
  $newHeight = (int)($originalHeight * $ratio);

  // 새 이미지 생성
  $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

  // PNG 투명도 유지
  if ($mimeType === 'image/png') {
    imagealphablending($resizedImage, false);
    imagesavealpha($resizedImage, true);
    $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
    imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
  }

  // 리샘플링
  imagecopyresampled(
    $resizedImage, $sourceImage,
    0, 0, 0, 0,
    $newWidth, $newHeight,
    $originalWidth, $originalHeight
);

  // 임시 파일로 저장
  $tempPath = sys_get_temp_dir() . '/' . uniqid('resized_') . '.jpg';

  imagejpeg($resizedImage, $tempPath, 85); // 85% 품질

  // 메모리 해제
  imagedestroy($sourceImage);
  imagedestroy($resizedImage);

  return $tempPath;
}