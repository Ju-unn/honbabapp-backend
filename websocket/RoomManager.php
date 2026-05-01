<?php

use Ratchet\ConnectionInterface;

/**
 * RoomManager
 * 채팅방별 WebSocket 연결을 관리하는 클래스
 *
 * $rooms 구조: [ room_id => [ user_id => ConnectionInterface ] ]
 * $connMap 구조: [ resourceId => [ 'room_id' => X, 'user_id' => Y ] ]  // leaveAll용 역방향 인덱스
 */
class RoomManager {

    /** @var array [room_id => [user_id => ConnectionInterface]] */
    private $rooms = [];

    /** @var array [resourceId => ['room_id' => int, 'user_id' => int]] */
    private $connMap = [];

    // ──────────────────────────────────────────────
    //  입장
    // ──────────────────────────────────────────────

    /**
     * 사용자를 특정 채팅방에 등록
     */
    public function join(int $roomId, int $userId, ConnectionInterface $conn): void {
        // 방이 없으면 초기화
        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = [];
        }

        // 연결 등록
        $this->rooms[$roomId][$userId] = $conn;

        // 역방향 인덱스 (onClose 시 어느 방에 있었는지 찾기 위해)
        $resourceId = $conn->resourceId;
        $this->connMap[$resourceId] = [
            'room_id' => $roomId,
            'user_id' => $userId,
        ];

        echo "[RoomManager] join → room:{$roomId}, user:{$userId}, resource:{$resourceId}\n";
    }

    // ──────────────────────────────────────────────
    //  퇴장
    // ──────────────────────────────────────────────

    /**
     * 사용자를 특정 채팅방에서 제거
     */
    public function leave(int $roomId, int $userId): void {
        if (isset($this->rooms[$roomId][$userId])) {
            $conn = $this->rooms[$roomId][$userId];
            unset($this->rooms[$roomId][$userId]);
            unset($this->connMap[$conn->resourceId]);
            echo "[RoomManager] leave → room:{$roomId}, user:{$userId}\n";
        }

        // 방에 아무도 없으면 방 자체 제거
        if (isset($this->rooms[$roomId]) && count($this->rooms[$roomId]) === 0) {
            unset($this->rooms[$roomId]);
            echo "[RoomManager] room {$roomId} 빈 방 제거\n";
        }
    }

    /**
     * 연결이 끊길 때 모든 방에서 해당 연결 제거
     * (네트워크 끊김 등 정상 leave 없이 연결이 종료된 경우)
     */
    public function leaveAll(ConnectionInterface $conn): void {
        $resourceId = $conn->resourceId;

        if (!isset($this->connMap[$resourceId])) {
            return; // 등록된 적 없는 연결
        }

        $roomId = $this->connMap[$resourceId]['room_id'];
        $userId = $this->connMap[$resourceId]['user_id'];

        $this->leave($roomId, $userId);
        echo "[RoomManager] leaveAll → resource:{$resourceId} 정리 완료\n";
    }

    // ──────────────────────────────────────────────
    //  브로드캐스트
    // ──────────────────────────────────────────────

    /**
     * 특정 채팅방의 모든 연결된 사용자에게 메시지 전송
     *
     * @param int    $roomId         대상 방 ID
     * @param string $json           전송할 JSON 문자열
     * @param int    $excludeUserId  제외할 사용자 ID (0이면 전체 전송)
     */
    public function broadcast(int $roomId, string $json, int $excludeUserId = 0): void {
        if (!isset($this->rooms[$roomId])) {
            return;
        }

        foreach ($this->rooms[$roomId] as $userId => $conn) {
            // 제외 대상 스킵
            if ($excludeUserId > 0 && $userId === $excludeUserId) {
                continue;
            }
            try {
                $conn->send($json);
            } catch (\Exception $e) {
                echo "[RoomManager] broadcast 전송 실패 user:{$userId} → " . $e->getMessage() . "\n";
            }
        }
    }

    // ──────────────────────────────────────────────
    //  조회
    // ──────────────────────────────────────────────

    /**
     * 특정 채팅방에 현재 연결된 user_id 배열 반환
     *
     * @return int[]
     */
    public function getMembers(int $roomId): array {
        if (!isset($this->rooms[$roomId])) {
            return [];
        }
        return array_keys($this->rooms[$roomId]);
    }

    /**
     * connMap에서 resourceId로 user_id 조회 (onClose 시 사용)
     */
    public function getUserIdByResource(int $resourceId): int {
        return $this->connMap[$resourceId]['user_id'] ?? 0;
    }

    /**
     * connMap에서 resourceId로 room_id 조회 (onClose 시 사용)
     */
    public function getRoomIdByResource(int $resourceId): int {
        return $this->connMap[$resourceId]['room_id'] ?? 0;
    }
}