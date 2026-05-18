<?php
/**
 * WebSocket Broadcast 헬퍼
 *
 * PHP API에서 Node.js WebSocket 서버로 이벤트 broadcast 요청을 보내는 헬퍼.
 *
 * 동작 방식:
 *   PHP → HTTP POST http://localhost:3000/emit      → Node.js → 방 멤버 전원 fan-out
 *   PHP → HTTP POST http://localhost:3000/emit_user → Node.js → 특정 user 한 명에게 직접
 *
 * 사용 예:
 *   emit_to_room(123, 'member_left', ['user_id' => 5, 'nickname' => '테스트 유저1']);
 *   emit_to_user(7,   'kicked',     ['room_id' => 123, 'target_user_id' => 7]);
 *
 */

// Node.js WebSocket 서버 주소 (PHP와 같은 EC2 안에 있으므로 localhost)
// 포트는 server.js 의 `new Server(3000, ...)` 와 일치해야 함
if (!defined('WS_EMIT_URL')) {
    define('WS_EMIT_URL', 'http://localhost:3000/emit');
}
if (!defined('WS_EMIT_USER_URL')) {
    define('WS_EMIT_USER_URL', 'http://localhost:3000/emit_user');
}

/**
 * curl 로 Node.js 에 POST 보내는 내부 헬퍼 (DRY)
 *
 * @param string $url   대상 URL (/emit 또는 /emit_user)
 * @param array  $body  body 로 보낼 데이터 (JSON 인코딩됨)
 * @return bool         성공 여부
 */
function _ws_post($url, $body) {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 1,   // 1초 안에 응답 안 오면 포기 (API 지연 방지)
        CURLOPT_CONNECTTIMEOUT => 1,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code !== 200) {
        error_log("[socket_emit] url={$url} http_code={$http_code} err={$err}");
        return false;
    }
    return true;
}

/**
 * 특정 방의 모든 멤버에게 이벤트 broadcast
 *
 * Node.js 측에서 chat_room_members 를 조회해 user_{id} 채널로 fan-out 함.
 *
 * @param int    $room_id  방 ID
 * @param string $event    이벤트명 (예: 'member_left', 'host_changed')
 * @param array  $payload  이벤트 데이터 (자동 JSON 변환)
 * @return bool            성공 여부 (실패해도 호출자는 무시 가능)
 */
function emit_to_room($room_id, $event, $payload = []) {
    return _ws_post(WS_EMIT_URL, [
        'room_id' => intval($room_id),
        'event'   => $event,
        'payload' => $payload,
    ]);
}

/**
 * 특정 user 1명에게 이벤트 직접 전송
 *
 * 사용처: 강퇴 등 "이미 방 멤버가 아닌 사람" 에게 알림이 가야 하는 경우.
 *         emit_to_room 은 chat_room_members 기준으로 fan-out 하므로
 *         방에서 빠진 사람한테는 못 보냄. user_{id} 개인 채널로 직접 보내야 함.
 *
 * @param int    $user_id  대상 user_id
 * @param string $event    이벤트명 (예: 'kicked')
 * @param array  $payload  이벤트 데이터
 * @return bool            성공 여부
 */
function emit_to_user($user_id, $event, $payload = []) {
    return _ws_post(WS_EMIT_USER_URL, [
        'user_id' => intval($user_id),
        'event'   => $event,
        'payload' => $payload,
    ]);
}
?>