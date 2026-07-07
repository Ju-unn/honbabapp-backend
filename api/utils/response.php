<?php 

function sendResponse($success, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 비즈니스 에러는 의도적으로 HTTP 200 유지 — 앱이 body의 success/message로 분기하고 있어
// HTTP 코드를 바꾸면 기존 onResponse 처리들이 깨짐. $code 인자는 호환용으로만 남겨둠.
function sendError($message, $code = 400) {
    sendResponse(false, $message, null, 200);
}

// 인증 실패 전용 — 실제 HTTP 401을 내려서 앱의 TokenRefreshAuthenticator(토큰 자동 갱신)를 트리거함
function sendAuthError($message) {
    sendResponse(false, $message, null, 401);
}


function sendSuccess($message, $data = null) {
    sendResponse(true, $message, $data, 200);
}
?>