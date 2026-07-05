<?php
/**
 * 모임/체험단 생성 API들이 공유하는 헬퍼 함수 모음
 *
 * api/meeting/create.php (일반 모임 전용) 와
 * api/campaign/campaign_create.php (체험단 전용) 에서
 * require_once 로 불러와 사용한다.
 *
 * 트랜잭션(beginTransaction/commit/rollBack)은 각 호출부에서 직접 관리하며,
 * 이 파일의 함수들은 이미 열린 트랜잭션 안에서 실행된다고 가정한다.
 */

/**
 * locations 테이블에 위치 정보를 INSERT하고 생성된 location_id를 반환한다.
 *
 * @param PDO   $databaseConnection
 * @param array $location 클라이언트가 보낸 location 객체
 * @return int  생성된 location_id
 */
function insertLocation(PDO $databaseConnection, array $location): int {
    $placeName   = $location['place_name']   ?? null;
    $address     = $location['address']      ?? null;
    $roadAddress = $location['road_address'] ?? null;
    $sido        = $location['sido']         ?? null;
    $sigungu     = $location['sigungu']      ?? null;
    $dong        = $location['dong']         ?? null;
    $mapX        = $location['map_x']        ?? null;
    $mapY        = $location['map_y']        ?? null;
    $category    = $location['category']     ?? null;
    $phoneNumber = $location['phone_number'] ?? null;

    $locationInsertQuery = "INSERT INTO locations (
        place_name, address, road_address, sido, sigungu, dong,
        map_x, map_y, category, phone_number
    ) VALUES (
        :place_name, :address, :road_address, :sido, :sigungu, :dong,
        :map_x, :map_y, :category, :phone_number
    )";

    $locationStatement = $databaseConnection->prepare($locationInsertQuery);
    $locationStatement->bindParam(':place_name',   $placeName);
    $locationStatement->bindParam(':address',      $address);
    $locationStatement->bindParam(':road_address', $roadAddress);
    $locationStatement->bindParam(':sido',         $sido);
    $locationStatement->bindParam(':sigungu',      $sigungu);
    $locationStatement->bindParam(':dong',         $dong);
    $locationStatement->bindParam(':map_x',        $mapX);
    $locationStatement->bindParam(':map_y',        $mapY);
    $locationStatement->bindParam(':category',     $category);
    $locationStatement->bindParam(':phone_number', $phoneNumber);
    $locationStatement->execute();

    return (int)$databaseConnection->lastInsertId();
}

/**
 * 모임 생성자(호스트)를 meeting_participants 테이블에 '승인' 상태로 자동 등록한다.
 *
 * @param PDO $databaseConnection
 * @param int $meetingId
 * @param int $hostUserId
 * @return void
 */
function insertHostParticipant(PDO $databaseConnection, int $meetingId, int $hostUserId): void {
    $participantInsertQuery = "INSERT INTO meeting_participants (
        meeting_id, user_id, participation_status, approved_at, approved_by
    ) VALUES (
        :meeting_id, :user_id, '승인', NOW(), :approved_by
    )";

    $participantStatement = $databaseConnection->prepare($participantInsertQuery);
    $participantStatement->bindParam(':meeting_id',  $meetingId,  PDO::PARAM_INT);
    $participantStatement->bindParam(':user_id',     $hostUserId, PDO::PARAM_INT);
    $participantStatement->bindParam(':approved_by', $hostUserId, PDO::PARAM_INT);
    $participantStatement->execute();
}
?>