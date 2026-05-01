<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../config/s3_config.php';
 
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}
 
$input = json_decode(file_get_contents('php://input'), true);
 
if (empty($input['user_id'])) {
    sendError('user_id가 필요합니다');
}
 
$user_id = intval($input['user_id']);
 
$database = new Database();
$db = $database->getConnection();
 
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // ── 트랜잭션 밖 ──────────────────────────────
    // 프로필 이미지 URL 조회 → S3 삭제
    // 트랜잭션 밖에서 하는 이유:
    // S3 삭제는 DB 롤백으로 되돌릴 수 없어서
    // DB 작업 전에 미리 처리해두는 거야
    $stmt0 = $db->prepare("SELECT user_profile_image FROM users WHERE user_id = :user_id");
    $stmt0->bindParam(":user_id" , $user_id , PDO::PARAM_INT);
    $stmt0->execute();
    $profileUrl = $stmt0->fetchColumn();

    if ($profileUrl) {
        $s3Config = new S3Config();
        $s3Config->deleteProfileImage($profileUrl);
    }

    // ── 트랜잭션 시작 ─────────────────────────────
    $db->beginTransaction();

    // 승인된 활성 참여 모임의 current_participants 감소
    // + 모집완료였으면 모집중으로 복구
    // 조건 : 내가 참여자이고 / 승인됐고 / 내가 호스트가 아닌 모임 / 아직 진행중인 모임
    $stmtA = $db->prepare("
        UPDATE meetings m
        JOIN meeting_participants mp ON mp.meeting_id = m.meeting_id
        SET m.meeting_current_participants = m.meeting_current_participants - 1,
        m.meeting_status = CASE
            WHEN m.meeting_status = '모집완료'
                AND (m.meeting_current_participants -1) < m.meeting_max_participants
            THEN '모집중' ELSE m.meeting_status END
        WHERE mp.user_id = :user_id
            AND mp.participation_status = '승인'
            AND m.meeting_host_id != :user_id2
            AND (m.meeting_date > NOW() OR m.meeting_status IN ('모집중' , '모집완료'))
            ");
    $stmtA->bindParam(":user_id" , $user_id , PDO::PARAM_INT);
    $stmtA->bindParam(":user_id2" , $user_id , PDO::PARAM_INT);
    $stmtA->execute();

    // 진행 중인 참가 기록만 삭제
    // 과거 이력(이미 종료된 모임)은 보존 -> 매너 점수 계산 등에 사용
    $stmtB = $db->prepare("
        DELETE mp FROM meeting_participants mp
        JOIN meetings m ON mp.meeting_id = m.meeting_id
        WHERE mp.user_id = :user_id
            AND m.meeting_host_id != :user_id2
            AND (m.meeting_date > NOW() OR m.meeting_status IN ('모집중' , '모집완료'))
            ");
    $stmtB->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmtB->bindParam(':user_id2', $user_id, PDO::PARAM_INT);
    $stmtB->execute();


    // 내가 호스트인 활성 모임 '취소'로 변경
    // 실제로 삭제하지 않고 취소 표시만 해서 참여자들이 확인 가능하게
    $stmtC = $db->prepare("
        UPDATE meetings SET meeting_status = '취소'
        WHERE meeting_host_id = :user_id
        AND meeting_status IN ('모집중','모집완료')
    ");
    $stmtC->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmtC->execute();

    // 탈퇴
    // user 행을 지우는게 아니라 상태만 바꾸기
    // user_id 동일하게 유지 재가입시 manner_score 악용방지
    // user_withdrawn_email: 이메일 NULL 전 원주소 보관 → 로그인 시 "탈퇴" 안내용 (컬럼 필요)
    $stmtD = $db->prepare("
        UPDATE users SET
            user_status = '탈퇴' ,
            user_deleted_at = NOW() ,
            user_email_verified = 0,
            user_withdrawn_email = user_email,
            user_email = NULL,
            user_nickname = NULL, 
            user_updated_at = NOW()
        WHERE user_id = :user_id AND user_status = '활성' ");

    $stmtD->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmtD->execute();

    // 실제로 업데이트 됐는지 확인
    // 이미 탈퇴한 계정이면 rowCount()가 0이야
    if ($stmtD->rowCount() === 0) {
        $db->rollBack();
        sendError('이미 탈퇴한 계정이거나 존재하지 않는 계정입니다');
    }

    $db->commit();
    sendSuccess('계정이 탈퇴 처리되었습니다');

} catch (PDOException $e) {
    // 중간에 에러 발생 시 전부 롤백 (아무것도 안 지워진 상태로 되돌아감)
    $db->rollBack();
    sendError('계정 탈퇴 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
}


?>