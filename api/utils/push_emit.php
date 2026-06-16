<?php
/**
 * FCM 푸시 알림 헬퍼
 *
 * PHP API에서 Node.js WebSocket 서버의 /push_user, /push_room 엔드포인트를 호출해
 * FCM 푸시 알림 발송을 요청하는 헬퍼.
 * socket_emit.php 와 동일한 패턴(curl, 1초 타임아웃, 실패 시 error_log)으로 작성.
 */

// Node.js WebSocket 서버 주소 (PHP와 같은 EC2 안에 있으므로 localhost)
// 포트는 server.js 의 `new Server(3000, ...)` 와 일치해야 함
if (!defined('WS_PUSH_USER_URL')) {
    define('WS_PUSH_USER_URL', 'http://localhost:3000/push_user');
}
if (!defined('WS_PUSH_ROOM_URL')) {
    define('WS_PUSH_ROOM_URL', 'http://localhost:3000/push_room');
}

/**
 * curl 로 Node.js 에 POST 보내는 내부 헬퍼 (DRY)
 * @param string $url   대상 URL (/push_user 또는 /push_room)
 * @param array  $body  body 로 보낼 데이터 (JSON 인코딩됨)
 * @return bool         성공 여부
 */
function _push_post($url, $body) {
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
        error_log("[push_emit] url={$url} http_code={$http_code} err={$err}");
        return false;
    }
    return true;
}

/**
 * 특정 user 1명에게 FCM 푸시 발송
 *
 * @param int    $user_id  대상 user_id
 * @param string $type     푸시 종류 (예: 'review_received', 'meeting_approved')
 * @param string $title    알림 제목
 * @param string $body     알림 본문
 * @param array  $data     딥링크 등에 쓸 추가 데이터 (FCM 전송 시 모든 값이 문자열로 변환됨)
 * @return bool            성공 여부 (실패해도 호출자는 무시 가능 — 알림은 best-effort)
 */
function push_to_user($user_id, $type, $title, $body, $data = []) {
    return _push_post(WS_PUSH_USER_URL, [
        'user_ids' => [intval($user_id)],
        'type'     => $type,
        'title'    => $title,
        'body'     => $body,
        'data'     => $data,
    ]);
}

/**
 * 특정 방의 멤버 전원에게 FCM 푸시 발송 (exclude_user_id 는 제외)
 *
 * @param int      $room_id          방 ID
 * @param int|null $exclude_user_id  제외할 user_id (없으면 null → 전원에게 발송)
 * @param string   $type             푸시 종류
 * @param string   $title            알림 제목
 * @param string   $body             알림 본문
 * @param array    $data             추가 데이터
 * @return bool                      성공 여부
 */
function push_to_room($room_id, $exclude_user_id, $type, $title, $body, $data = []) {
    return _push_post(WS_PUSH_ROOM_URL, [
        'room_id'         => intval($room_id),
        'exclude_user_id' => $exclude_user_id !== null ? intval($exclude_user_id) : null,
        'type'            => $type,
        'title'           => $title,
        'body'            => $body,
        'data'            => $data,
    ]);
}
?>