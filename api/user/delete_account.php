<?php
require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../config/s3_config.php';
require_once '../utils/jwt.php';
require_once '../utils/socket_emit.php';
 
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}
 
// 본인 확인 (body의 user_id를 신뢰하지 않음 — 위조 방지)
$user_id = get_user_id_from_token();
 
$database = new Database();
$db = $database->getConnection();
 
if ($db === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    // ── 탈퇴 가능 여부 확인 ───────────────────────
    // 진행 중(모집중/모집완료 + 날짜 미도래)인 모임/체험단에
    // 호스트이거나 승인된 참여자로 걸려 있으면 탈퇴 불가.
    // S3 삭제는 되돌릴 수 없으므로 반드시 그보다 먼저 체크한다.
    // (대기 중인 신청은 아래 정리 로직이 자동 취소하므로 차단 대상 아님)
    $active_check_stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN m.meeting_type = '체험단' THEN 1 ELSE 0 END) AS active_campaigns,
            SUM(CASE WHEN m.meeting_type != '체험단' THEN 1 ELSE 0 END) AS active_meetings
        FROM meetings m
        LEFT JOIN meeting_participants mp
            ON mp.meeting_id = m.meeting_id
            AND mp.user_id = :user_id
            AND mp.participation_status = '승인'
        WHERE (m.meeting_host_id = :user_id2 OR mp.user_id IS NOT NULL)
            AND m.meeting_status IN ('모집중', '모집완료')
            AND m.meeting_date > NOW()
    ");
    $active_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $active_check_stmt->bindParam(':user_id2', $user_id, PDO::PARAM_INT);
    $active_check_stmt->execute();
    $active_counts = $active_check_stmt->fetch(PDO::FETCH_ASSOC);

    $active_meeting_count = intval($active_counts['active_meetings'] ?? 0);
    $active_campaign_count = intval($active_counts['active_campaigns'] ?? 0);

    if ($active_meeting_count > 0 || $active_campaign_count > 0) {
        $blocking_items = [];
        if ($active_meeting_count > 0) {
            $blocking_items[] = '모임 ' . $active_meeting_count . '건';
        }
        if ($active_campaign_count > 0) {
            $blocking_items[] = '체험단 ' . $active_campaign_count . '건';
        }
        sendError('진행 중인 ' . implode(', ', $blocking_items)
            . '이(가) 있어 탈퇴할 수 없습니다. 모임 나가기 또는 삭제 후 다시 시도해주세요.');
    }

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

    // 탈퇴 처리로 닉네임이 NULL 되기 전에, 실시간 알림(member_left) payload용으로 미리 떠둔다
    $nicknameStmt = $db->prepare("SELECT user_nickname FROM users WHERE user_id = :user_id");
    $nicknameStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $nicknameStmt->execute();
    $leaving_nickname = $nicknameStmt->fetchColumn() ?: '탈퇴한 유저';

    // 커밋 후 emit할 이벤트를 여기 모아뒀다가 트랜잭션 밖에서 best-effort로 쏜다
    $host_changed_events = [];

    // ── 채팅 정리 ─────────────────────────────────
    // (1) 내가 방장(owner_id)인 group 채팅방 처리
    //     leave.php 호스트 나가기와 같은 정책:
    //     - 남은 멤버가 있으면 매너점수 DESC, 입장순 ASC 1위에게 방장 위임
    //     - 나 혼자면 방 데이터 전체 정리
    //     (모임 자체는 위 stmtC에서 '취소' 처리됨 — 채팅방은 참여자들이
    //      계속 쓸 수 있게 유지하되 방장만 넘긴다)
    $ownedRoomsStmt = $db->prepare("
        SELECT room_id FROM chat_rooms
        WHERE owner_id = :user_id AND room_type = 'group'
        FOR UPDATE
    ");
    $ownedRoomsStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $ownedRoomsStmt->execute();
    $owned_rooms = $ownedRoomsStmt->fetchAll(PDO::FETCH_COLUMN);

    $candidateStmt = $db->prepare("
        SELECT crm.user_id, u.user_nickname
        FROM chat_room_members crm
        JOIN users u ON crm.user_id = u.user_id
        WHERE crm.room_id = :room_id AND crm.user_id != :user_id
        ORDER BY u.user_manner_score DESC, crm.joined_at ASC
        LIMIT 1
    ");

    foreach ($owned_rooms as $owned_room_id) {
        $candidateStmt->execute([':room_id' => $owned_room_id, ':user_id' => $user_id]);
        $candidate = $candidateStmt->fetch(PDO::FETCH_ASSOC);
        $new_owner_id = $candidate ? $candidate['user_id'] : null;

        if ($new_owner_id) {
            $stmt = $db->prepare("UPDATE chat_rooms SET owner_id = ? WHERE room_id = ?");
            $stmt->execute([intval($new_owner_id), $owned_room_id]);

            $host_changed_events[] = [
                'room_id'      => $owned_room_id,
                'new_host_id'  => intval($new_owner_id),
                'new_nickname' => $candidate['user_nickname'],
            ];
        } else {
            // 나 혼자 남은 방 → 통째 정리 (leave.php와 동일한 FK 순서:
            // chat_message_reads → chat_messages → chat_room_members → chat_rooms)
            $stmt = $db->prepare("
                DELETE cmr FROM chat_message_reads cmr
                INNER JOIN chat_messages cm ON cmr.message_id = cm.message_id
                WHERE cm.room_id = ?
            ");
            $stmt->execute([$owned_room_id]);

            $stmt = $db->prepare("DELETE FROM chat_messages WHERE room_id = ?");
            $stmt->execute([$owned_room_id]);

            $stmt = $db->prepare("DELETE FROM chat_room_members WHERE room_id = ?");
            $stmt->execute([$owned_room_id]);

            $stmt = $db->prepare("DELETE FROM chat_rooms WHERE room_id = ?");
            $stmt->execute([$owned_room_id]);
        }
    }

    // 지금 시점(멤버십 삭제 직전) 기준으로 내가 속한 방 목록을 미리 떠둔다
    // → member_left 알림 대상 (커밋 후 remaining 재조회해서 emit)
    $remainingRoomsStmt = $db->prepare("SELECT room_id FROM chat_room_members WHERE user_id = :user_id");
    $remainingRoomsStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $remainingRoomsStmt->execute();
    $rooms_to_notify = $remainingRoomsStmt->fetchAll(PDO::FETCH_COLUMN);

    // 탈퇴는 leave.php/kick.php와 달리 익명화한다 — 실제 닉네임/사진 대신
    // 고정값 '탈퇴한 유저'/NULL을 그 방에 내가 보낸 메시지 전체에 박아넣는다.
    // (복구해도 이 방들엔 재초대되기 전까진 계속 "탈퇴한 유저"로 남음.
    //  재초대되면 messages.php가 live join으로 우선 표시하니 자동으로 실시간 값으로 바뀜)
    $snapshotBackfillStmt = $db->prepare("
        UPDATE chat_messages
        SET sender_nickname_snapshot = '탈퇴한 유저',
            sender_profile_image_snapshot = NULL
        WHERE room_id = :room_id AND sender_id = :user_id
    ");
    foreach ($rooms_to_notify as $notify_room_id) {
        $snapshotBackfillStmt->execute([
            ':room_id' => $notify_room_id,
            ':user_id' => $user_id,
        ]);
    }

    // (2) 모든 채팅방 멤버십 제거 (유령 멤버 방지 —
    //     남은 방들의 참여자 목록/인원수에서 탈퇴자가 빠지게 됨)
    $stmtE = $db->prepare("DELETE FROM chat_room_members WHERE user_id = :user_id");
    $stmtE->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmtE->execute();

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
            user_profile_image = NULL,
            fcm_token = NULL,
            fcm_token_updated_at = NOW(),
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

    // ── WebSocket broadcast (트랜잭션 밖, best-effort) ──
    // leave.php와 동일하게: 실패해도 탈퇴 응답 자체엔 영향 없음.
    // 열려있는 채팅방 화면(입력창 막기, "N명" 인원수 등)이 실시간으로 갱신되게.
    foreach ($host_changed_events as $event) {
        emit_to_room($event['room_id'], 'host_changed', $event);
    }

    $remainingCountStmt = $db->prepare("SELECT COUNT(*) FROM chat_room_members WHERE room_id = ?");
    foreach ($rooms_to_notify as $notify_room_id) {
        $remainingCountStmt->execute([$notify_room_id]);
        $remaining = intval($remainingCountStmt->fetchColumn());

        emit_to_room($notify_room_id, 'member_left', [
            'room_id'   => $notify_room_id,
            'user_id'   => $user_id,
            'nickname'  => $leaving_nickname,
            'remaining' => $remaining,
        ]);
    }

    sendSuccess('계정이 탈퇴 처리되었습니다');

} catch (PDOException $e) {
    // 중간에 에러 발생 시 전부 롤백 (아무것도 안 지워진 상태로 되돌아감)
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sendError('계정 탈퇴 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
}


?>