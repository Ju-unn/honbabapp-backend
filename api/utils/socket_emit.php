<?php
/**
 * WebSocket Broadcast 헬퍼
 *
 * PHP API에서 Node.js WebSocket 서버로 이벤트 broadcast 요청을 보내는 헬퍼.
 *
 * 동작 방식:
 *   PHP → HTTP POST http://localhost:3000/emit → Node.js → 멤버들에게 fan-out
 *
 * 사용 예:
 *   emit_to_room(123, 'member_left', ['user_id' => 5, 'nickname' => '테스트 유저1']);
 *
 * 주의:
 *   - best-effort : Node.js 서버가 꺼져 있어도 PHP 응답은 정상 처리됨
 *   - 운영 단계에서 인증 추가 (X-Internal-Auth 헤더) 예정 — V2
 *   - timeout 짧게 (1초) : API 응답 지연 방지
 */

// Node.js WebSocket 서버 주소 (PHP와 같은 EC2 안에 있으므로 localhost)
// 포트는 server.js 의 `new Server(3000, ...)` 와 일치해야 함
if (!defined('WS_EMIT_URL')) {
    define('WS_EMIT_URL', 'http://localhost:3000/emit');
}

/**
 * 특정 방의 모든 멤버에게 이벤트 broadcast
 *
 * @param int    $room_id  방 ID
 * @param string $event    이벤트명 (예: 'member_left', 'host_changed')
 * @param array  $payload  이벤트 데이터 (자동 JSON 변환)
 * @return bool            성공 여부 (실패해도 호출자는 무시 가능)
 */
function emit_to_room($room_id, $event, $payload = []) {
    $body = json_encode([
        'room_id' => intval($room_id),
        'event'   => $event,
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(WS_EMIT_URL);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 1,   // 1초 안에 응답 안 오면 포기 (API 지연 방지)
        CURLOPT_CONNECTTIMEOUT => 1,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    // 실패해도 throw 하지 않음 — broadcast 는 best-effort
    if ($err || $http_code !== 200) {
        error_log("[socket_emit] room_id={$room_id} event={$event} "
                . "http_code={$http_code} err={$err}");
        return false;
    }

    return true;
}
?>