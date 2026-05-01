<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/RoomManager.php';
require_once __DIR__ . '/../api/config/database.php';

/**
 * ChatServer
 * Ratchet MessageComponentInterface 구현
 * 모든 WebSocket 이벤트(연결/메시지/종료/에러)를 처리
 */
class ChatServer implements MessageComponentInterface {

    /** @var RoomManager */
    private $roomManager;

    /** @var PDO DB 연결 (단일 연결 재사용) */
    private $db;

    public function __construct() {
        $this->roomManager = new RoomManager();

        // 서버 시작 시 DB 연결 1회 생성
        $database = new Database();
        $this->db = $database->getConnection();

        if (!$this->db) {
            echo "[ChatServer] DB 연결 실패! 서버는 시작되지만 메시지 저장 불가\n";
        } else {
            echo "[ChatServer] DB 연결 성공\n";
        }
    }

    // ──────────────────────────────────────────────
    //  Ratchet 이벤트 핸들러
    // ──────────────────────────────────────────────

    /**
     * 새 클라이언트 연결 시
     */
    public function onOpen(ConnectionInterface $conn): void {
        echo "[onOpen] 새 연결 → resource:{$conn->resourceId}\n";
        // join 메시지를 받기 전까지는 아무 방에도 속하지 않음
    }

    /**
     * 메시지 수신 시
     */
    public function onMessage(ConnectionInterface $from, $json): void {
        $msg = json_decode($json);

        if (!$msg || !isset($msg->type)) {
            $from->send(json_encode(['type' => 'error', 'message' => '잘못된 메시지 형식']));
            return;
        }

        echo "[onMessage] type:{$msg->type} resource:{$from->resourceId}\n";

        switch ($msg->type) {
            case 'join':
                $this->handleJoin($from, $msg);
                break;

            case 'message':
                $this->handleMessage($from, $msg);
                break;

            case 'read':
                $this->handleRead($from, $msg);
                break;

            case 'leave':
                $this->handleLeave($from, $msg);
                break;

            default:
                $from->send(json_encode(['type' => 'error', 'message' => '알 수 없는 타입: ' . $msg->type]));
        }
    }

    /**
     * 연결 종료 시 (정상/비정상 모두)
     */
    public function onClose(ConnectionInterface $conn): void {
        $resourceId = $conn->resourceId;
        $roomId = $this->roomManager->getRoomIdByResource($resourceId);
        $userId = $this->roomManager->getUserIdByResource($resourceId);

        echo "[onClose] 연결 종료 → resource:{$resourceId}\n";

        if ($roomId > 0 && $userId > 0) {
            // 시스템 메시지 없이 조용히 퇴장 처리 (비정상 종료이므로)
            $this->roomManager->leave($roomId, $userId);
        } else {
            $this->roomManager->leaveAll($conn);
        }
    }

    /**
     * 에러 발생 시
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[onError] resource:{$conn->resourceId} → " . $e->getMessage() . "\n";
        $conn->close();
    }

    // ──────────────────────────────────────────────
    //  타입별 처리 메서드
    // ──────────────────────────────────────────────

    /**
     * join: 방 입장 처리
     */
    private function handleJoin(ConnectionInterface $conn, $msg): void {
        $roomId = (int)($msg->room_id ?? 0);
        $userId = (int)($msg->user_id ?? 0);

        if ($roomId <= 0 || $userId <= 0) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'room_id, user_id 필수']));
            return;
        }

        $this->roomManager->join($roomId, $userId, $conn);

        // 입장 확인 응답 (옵션)
        $conn->send(json_encode([
            'type'    => 'system',
            'room_id' => $roomId,
            'content' => '채팅방에 연결되었습니다.',
        ]));
    }

    /**
     * message: 채팅 메시지 처리
     * 1. DB 저장 → 2. 브로드캐스트 → 3. last_message 업데이트 → 4. FCM 알림
     */
    private function handleMessage(ConnectionInterface $from, $msg): void {
        $roomId  = (int)($msg->room_id  ?? 0);
        $userId  = (int)($msg->user_id  ?? 0);
        $content = trim($msg->content   ?? '');
        $msgType = $msg->msg_type ?? 'text';

        if ($roomId <= 0 || $userId <= 0 || $content === '') {
            $from->send(json_encode(['type' => 'error', 'message' => 'room_id, user_id, content 필수']));
            return;
        }

        // ── 1. 메시지 DB 저장 ──────────────────────
        $messageId = $this->insertMessage($roomId, $userId, $content, $msgType);
        if (!$messageId) {
            $from->send(json_encode(['type' => 'error', 'message' => 'DB 저장 실패']));
            return;
        }

        // ── 2. 발신자 닉네임 조회 ──────────────────
        $senderName = $this->getSenderName($userId);

        // ── 3. 현재 방의 안읽은 인원 수 계산 ───────
        $unreadCount = $this->getUnreadCount($roomId, $messageId);

        // ── 4. 메시지 생성 시각 조회 ────────────────
        $createdAt = $this->getMessageCreatedAt($messageId);

        // ── 5. 브로드캐스트 (전체에게, 발신자 포함) ─
        $broadcastData = json_encode([
            'type'        => 'message',
            'message_id'  => $messageId,
            'room_id'     => $roomId,
            'sender_id'   => $userId,
            'sender_name' => $senderName,
            'content'     => $content,
            'msg_type'    => $msgType,
            'unread_count'=> $unreadCount,
            'created_at'  => $createdAt,
        ], JSON_UNESCAPED_UNICODE);

        $this->roomManager->broadcast($roomId, $broadcastData);

        // ── 6. chat_rooms last_message 업데이트 ────
        $this->updateLastMessage($roomId, $content);

        // // ── 7. 오프라인 멤버 FCM 알림 ───────────────
        // $onlineMembers = $this->roomManager->getMembers($roomId);
        // $this->sendFcmToOfflineMembers($roomId, $userId, $senderName, $content, $onlineMembers);
    }

    /**
     * read: 읽음 처리
     * 1. chat_message_reads INSERT IGNORE → 2. 브로드캐스트
     */
    private function handleRead(ConnectionInterface $conn, $msg): void {
        $roomId = (int)($msg->room_id ?? 0);
        $userId = (int)($msg->user_id ?? 0);

        if ($roomId <= 0 || $userId <= 0) {
            return;
        }

        // ── 1. 읽지 않은 메시지 ID 목록 조회 ────────
        $unreadMessageIds = $this->getUnreadMessageIds($roomId, $userId);

        if (empty($unreadMessageIds)) {
            return; // 읽을 메시지 없음
        }

        // ── 2. chat_message_reads INSERT IGNORE ─────
        $this->insertReadRecords($unreadMessageIds, $userId);

        // ── 3. last_read_at 갱신 ─────────────────────
        $this->updateLastReadAt($roomId, $userId);

        // ── 4. 방 전체에 read_update 브로드캐스트 ────
        $broadcastData = json_encode([
            'type'        => 'read_update',
            'room_id'     => $roomId,
            'reader_id'   => $userId,
            'message_ids' => $unreadMessageIds,
        ], JSON_UNESCAPED_UNICODE);

        $this->roomManager->broadcast($roomId, $broadcastData);
    }

    /**
     * leave: 채팅방 나가기
     * 1. RoomManager에서 제거 → 2. 시스템 메시지 저장 → 3. 브로드캐스트
     */
    private function handleLeave(ConnectionInterface $conn, $msg): void {
        $roomId = (int)($msg->room_id ?? 0);
        $userId = (int)($msg->user_id ?? 0);

        if ($roomId <= 0 || $userId <= 0) {
            return;
        }

        // ── 1. 닉네임 조회 ───────────────────────────
        $nickname = $this->getSenderName($userId);

        // ── 2. 시스템 메시지 DB 저장 ─────────────────
        $systemContent = "{$nickname}님이 나갔습니다.";
        $this->insertMessage($roomId, null, $systemContent, 'system');

        // ── 3. 방 전체에 브로드캐스트 (나가는 사람 포함) ─
        $broadcastData = json_encode([
            'type'    => 'system',
            'room_id' => $roomId,
            'content' => $systemContent,
        ], JSON_UNESCAPED_UNICODE);

        $this->roomManager->broadcast($roomId, $broadcastData);

        // ── 4. RoomManager에서 제거 ──────────────────
        $this->roomManager->leave($roomId, $userId);
    }

    // ──────────────────────────────────────────────
    //  DB 헬퍼 메서드
    // ──────────────────────────────────────────────

    /**
     * 메시지 INSERT → 삽입된 message_id 반환 (실패 시 0)
     */
    private function insertMessage(int $roomId, ?int $senderId, string $content, string $msgType): int {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at)
                 VALUES (:room_id, :sender_id, :msg_type, :content, NOW())"
            );
            $stmt->execute([
                ':room_id'   => $roomId,
                ':sender_id' => $senderId,
                ':msg_type'  => $msgType,
                ':content'   => $content,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            echo "[ChatServer] insertMessage 오류: " . $e->getMessage() . "\n";
            return 0;
        }
    }

    /**
     * 발신자 닉네임 조회
     */
    private function getSenderName(int $userId): string {
        try {
            $stmt = $this->db->prepare("SELECT user_nickname FROM users WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row['user_nickname'] ?? '알 수 없음';
        } catch (\PDOException $e) {
            return '알 수 없음';
        }
    }

    /**
     * 메시지의 안읽은 인원 수 계산
     * (방 멤버 수 - 이미 읽은 인원 수)
     */
    private function getUnreadCount(int $roomId, int $messageId): int {
        try {
            // 방 전체 멤버 수
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM chat_room_members WHERE room_id = :room_id"
            );
            $stmt->execute([':room_id' => $roomId]);
            $totalMembers = (int)$stmt->fetchColumn();

            // 읽은 인원 수
            $stmt2 = $this->db->prepare(
                "SELECT COUNT(*) FROM chat_message_reads WHERE message_id = :mid"
            );
            $stmt2->execute([':mid' => $messageId]);
            $readCount = (int)$stmt2->fetchColumn();

            return max(0, $totalMembers - $readCount);
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * 메시지 created_at 조회
     */
    private function getMessageCreatedAt(int $messageId): string {
        try {
            $stmt = $this->db->prepare(
                "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
                 FROM chat_messages WHERE message_id = :mid"
            );
            $stmt->execute([':mid' => $messageId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row['created_at'] ?? date('Y-m-d H:i:s');
        } catch (\PDOException $e) {
            return date('Y-m-d H:i:s');
        }
    }

    /**
     * chat_rooms last_message, last_msg_time 업데이트
     */
    private function updateLastMessage(int $roomId, string $content): void {
        try {
            $preview = mb_substr($content, 0, 100); // 미리보기 100자
            $stmt = $this->db->prepare(
                "UPDATE chat_rooms SET last_message = :content, last_msg_time = NOW()
                 WHERE room_id = :room_id"
            );
            $stmt->execute([':content' => $preview, ':room_id' => $roomId]);
        } catch (\PDOException $e) {
            echo "[ChatServer] updateLastMessage 오류: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 특정 사용자의 읽지 않은 메시지 ID 목록 조회
     * (chat_message_reads에 없는 메시지 = 안 읽은 메시지)
     */
    private function getUnreadMessageIds(int $roomId, int $userId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.message_id
                 FROM chat_messages m
                 LEFT JOIN chat_message_reads r
                   ON m.message_id = r.message_id AND r.user_id = :user_id
                 WHERE m.room_id = :room_id
                   AND r.id IS NULL
                   AND (m.sender_id != :user_id2 OR m.sender_id IS NULL)
                 ORDER BY m.message_id ASC"
            );
            $stmt->execute([
                ':room_id'  => $roomId,
                ':user_id'  => $userId,
                ':user_id2' => $userId,
            ]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        } catch (\PDOException $e) {
            echo "[ChatServer] getUnreadMessageIds 오류: " . $e->getMessage() . "\n";
            return [];
        }
    }

    /**
     * chat_message_reads 읽음 기록 일괄 INSERT IGNORE
     */
    private function insertReadRecords(array $messageIds, int $userId): void {
        if (empty($messageIds)) return;

        try {
            // 한 번의 쿼리로 일괄 INSERT
            $placeholders = implode(',', array_map(
                fn($id) => "({$id}, {$userId}, NOW())",
                array_map('intval', $messageIds)
            ));

            $this->db->exec(
                "INSERT IGNORE INTO chat_message_reads (message_id, user_id, read_at)
                 VALUES {$placeholders}"
            );
        } catch (\PDOException $e) {
            echo "[ChatServer] insertReadRecords 오류: " . $e->getMessage() . "\n";
        }
    }

    /**
     * chat_room_members last_read_at 갱신
     */
    private function updateLastReadAt(int $roomId, int $userId): void {
        try {
            $stmt = $this->db->prepare(
                "UPDATE chat_room_members SET last_read_at = NOW()
                 WHERE room_id = :room_id AND user_id = :user_id"
            );
            $stmt->execute([':room_id' => $roomId, ':user_id' => $userId]);
        } catch (\PDOException $e) {
            echo "[ChatServer] updateLastReadAt 오류: " . $e->getMessage() . "\n";
        }
    }

    // ──────────────────────────────────────────────
    //  FCM 알림 (오프라인 멤버용)
    // ──────────────────────────────────────────────

    /**
     * 방 멤버 중 현재 WebSocket에 연결되지 않은(오프라인) 멤버에게 FCM 알림 전송
     * HTTP 내부 요청 → /api/chat/send_notification.php
     */
    // private function sendFcmToOfflineMembers(
    //     int    $roomId,
    //     int    $senderId,
    //     string $senderName,
    //     string $content,
    //     array  $onlineMemberIds
    // ): void {
    //     try {
    //         // 방 전체 멤버 조회
    //         $stmt = $this->db->prepare(
    //             "SELECT user_id FROM chat_room_members WHERE room_id = :room_id"
    //         );
    //         $stmt->execute([':room_id' => $roomId]);
    //         $allMembers = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

    //         // 오프라인 = 전체 멤버 - 온라인 멤버 - 발신자 본인
    //         $offlineMembers = array_diff($allMembers, $onlineMemberIds, [$senderId]);

    //         if (empty($offlineMembers)) {
    //             return;
    //         }

    //         // send_notification.php 내부 HTTP 호출
    //         $payload = json_encode([
    //             'room_id'      => $roomId,
    //             'sender_name'  => $senderName,
    //             'content'      => $content,
    //             'target_users' => array_values($offlineMembers),
    //         ]);

    //         $ch = curl_init('http://localhost/api/chat/send_notification.php');
    //         curl_setopt_array($ch, [
    //             CURLOPT_POST           => true,
    //             CURLOPT_POSTFIELDS     => $payload,
    //             CURLOPT_RETURNTRANSFER => true,
    //             CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    //             CURLOPT_TIMEOUT        => 3, // 3초 타임아웃 (채팅 흐름 방해 방지)
    //         ]);
    //         curl_exec($ch);
    //         curl_close($ch);

    //     } catch (\Exception $e) {
    //         echo "[ChatServer] sendFcmToOfflineMembers 오류: " . $e->getMessage() . "\n";
    //     }
    // }
}