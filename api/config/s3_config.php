<?php
/**
 * AWS S3 설정 파일
 * 
 * honbabapp-images 버킷에 프로필 이미지 업로드를 위한 설정
 */

 require_once __DIR__ . '/../../vendor/autoload.php';
 
// .env 파일 로드
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

 use Aws\S3\S3Client;
 use Aws\Exception\AwsException;

 class S3Config{

  //S3 버킷 설정
  private const BUCKET_NAME = 'honbabapp-images';
  private const REGION = 'ap-northeast-2';
  private const ACL = 'public-read';

  // 프로필 이미지 업로드 경로
  private const PROFILE_IMAGE_PATH = 'profiles/';

  // 모임 이미지 업로드 경로
  private const MEETING_IMAGE_PATH = "meetings/";

  // 채팅 이미지 업로드 경로
  private const CHAT_IMAGE_PATH = "chat/";

  // IAM 사용자 엑세스 키 
  private $accessKey;
  private $secretKey;

  private $s3Client;
  
  public function __construct() {

  // 환경 변수에서 자격 증명 가져오기
  $this->accessKey = $_ENV['AWS_ACCESS_KEY_ID'];
  $this->secretKey = $_ENV['AWS_SECRET_ACCESS_KEY'];

  if (empty($this->accessKey) || empty($this->secretKey)) {
    error_log('AWS 자격 증명이 없습니다.');
    throw new Exception('AWS 자격 증명 오류');
  }

  $this->initializeS3Client();
}

   /**
     * S3 클라이언트 초기화
     */
    private function initializeS3Client() {
      try {
          $this->s3Client = new S3Client([
              'version' => 'latest',
              'region'  => self::REGION,
              'credentials' => [
                  'key'    => $this->accessKey,
                  'secret' => $this->secretKey,
              ]
          ]);
      } catch (AwsException $e) {
          error_log('S3 클라이언트 초기화 실패: ' . $e->getMessage());
          throw new Exception('S3 연결 실패');
      }
  }

     /**
     * 프로필 이미지를 S3에 업로드
     * 
     */

     public function uploadProfileImage($filePath , $fileName , $mimeType) {
      try{
        // S3 키 생성 -> 경로 + 파일이름 + 타입
        $s3Key = self::PROFILE_IMAGE_PATH . $fileName;

        // S3 업로드
        $this->s3Client->putObject([
          'Bucket' => self::BUCKET_NAME ,
          'Key' => $s3Key ,
          'SourceFile' => $filePath ,
          'ContentType' => $mimeType ,
        ]);

        return $this->s3Client->getObjectUrl(self::BUCKET_NAME, $s3Key);
      } catch (AwsException $e) {
        error_log('S3 업로드 실패: ' . $e->getMessage());
        throw new Exception('이미지 업로드 실패: ' . $e->getMessage());
    }
  }

  /**
   * 모임 이미지를 S3에 업로드
   *
   * 네이버 이미지 검색으로 가져온 사진을 Android에서 임시 파일로 저장 후
   * upload_meeting_image.php 를 통해 이 메서드가 호출됨
   *
   * @param string $filePath  서버에 업로드된 임시 파일 경로 ($_FILES['meeting_image']['tmp_name'])
   * @param string $fileName  S3에 저장할 파일명 (meeting_{timestamp}_{random}.jpg)
   * @param string $mimeType  MIME 타입 (image/jpeg)
   * @return string           S3 퍼블릭 URL
   */

   public function uploadMeetingImage($filePath, $fileName, $mimeType) {
    try {
        // S3 키 생성
        $s3Key = self::MEETING_IMAGE_PATH . $fileName;

        // S3 업로드
        $this->s3Client->putObject([
            'Bucket'      => self::BUCKET_NAME,
            'Key'         => $s3Key,
            'SourceFile'  => $filePath,
            'ContentType' => $mimeType,
        ]);

        return $this->s3Client->getObjectUrl(self::BUCKET_NAME, $s3Key);
    } catch (AwsException $e) {
        error_log('S3 모임 이미지 업로드 실패: ' . $e->getMessage());
        throw new Exception('모임 이미지 업로드 실패: ' . $e->getMessage());
    }
}

 /**
   * 채팅 이미지를 S3에 업로드
   *
   * Android 에서 갤러리/카메라로 가져온 이미지를
   * upload_chat_image.php 를 통해 이 메서드가 호출됨
   *
   * @param string $filePath  서버에 업로드된 임시 파일 경로 ($_FILES['chat_image']['tmp_name'])
   * @param string $fileName  S3에 저장할 파일명 (chat_{room}_{user}_{timestamp}_{random}.{ext})
   * @param string $mimeType  MIME 타입 (image/jpeg, image/png, image/webp)
   * @return string           S3 퍼블릭 URL
   */
  public function uploadChatImage($filePath, $fileName, $mimeType) {
    try {
        // S3 키 생성
        $s3Key = self::CHAT_IMAGE_PATH . $fileName;
 
        // S3 업로드
        $this->s3Client->putObject([
            'Bucket'      => self::BUCKET_NAME,
            'Key'         => $s3Key,
            'SourceFile'  => $filePath,
            'ContentType' => $mimeType,
        ]);
 
        return $this->s3Client->getObjectUrl(self::BUCKET_NAME, $s3Key);
    } catch (AwsException $e) {
        error_log('S3 채팅 이미지 업로드 실패: ' . $e->getMessage());
        throw new Exception('채팅 이미지 업로드 실패: ' . $e->getMessage());
    }
  } 




   /**
     * S3에서 이미지 삭제 (프로필 변경 시 기존 이미지 삭제용)
     * 
     */
    public function deleteProfileImage($s3Url) {
      try {
        // URL에서 S3 키 추출
        $parsedUrl = parse_url($s3Url);
        $s3Key = ltrim($parsedUrl['path'], '/');

        $this->s3Client->deleteObject([
          'Bucket' => self::BUCKET_NAME ,
          'Key' => $s3Key ,
        ]);

        return true;
      }catch (AwsException $e) {
        error_log('S3 삭제 실패: ' . $e->getMessage());
        return false;
      }
    }

    /**
     * 버킷 이름 반환
     */
    public function getBucketName() {
      return self::BUCKET_NAME;
  }
  
  /**
   * 리전 반환
   */
  public function getRegion() {
      return self::REGION;
  }
  
  /**
   * 프로필 이미지 경로 반환
   */
  public function getProfileImagePath() {
      return self::PROFILE_IMAGE_PATH;
  }

}
?>