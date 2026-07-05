<?php
/**
 * 체험단 등록권 결제 승인 API
 * POST /api/payment/confirm.php
 *
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$authenticated_user_id = get_user_id_from_token();

$request_body = json_decode(file_get_contents('php://input'), true);

if (empty($request_body['payment_key']) || empty($request_body['order_id']) || !isset($request_body['amount'])) {
    sendError('payment_key, order_id, amount가 모두 필요합니다');
}

$received_payment_key = trim($request_body['payment_key']);
$received_order_id    = trim($request_body['order_id']);
$received_amount      = intval($request_body['amount']);

$database_connection = new Database();
$database = $database_connection->getConnection();

if ($database === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $database->beginTransaction();

    // ── 1. 주문 조회 + 잠금 (동시 요청/중복 승인 방지) ────────
    $lock_order_statement = $database->prepare("
        SELECT payment_id, user_id, amount, status, ticket_count
        FROM payments
        WHERE order_id = :order_id
        FOR UPDATE
    ");
    $lock_order_statement->bindParam(':order_id', $received_order_id);
    $lock_order_statement->execute();

    if ($lock_order_statement->rowCount() === 0) {
        $database->rollBack();
        sendError('존재하지 않는 주문입니다');
    }

    $payment_record = $lock_order_statement->fetch(PDO::FETCH_ASSOC);

    if ((int)$payment_record['user_id'] !== $authenticated_user_id) {
        $database->rollBack();
        sendError('본인의 주문이 아닙니다', 403);
    }

    if ($payment_record['status'] !== 'READY') {
        $database->rollBack();
        sendError('이미 처리된 주문입니다');
    }

    if ((int)$payment_record['amount'] !== $received_amount) {
        $database->rollBack();
        sendError('결제 금액이 일치하지 않습니다');
    }

    // ── 2. 토스페이먼츠 결제 승인 API 호출 ────────────────────
    $toss_secret_key = getenv('TOSS_SECRET_KEY');
    $encoded_authorization_header = base64_encode($toss_secret_key . ':');

    $curl_handle = curl_init();
    curl_setopt_array($curl_handle, [
        CURLOPT_URL => 'https://api.tosspayments.com/v1/payments/confirm',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'paymentKey' => $received_payment_key,
            'orderId'    => $received_order_id,
            'amount'     => $received_amount,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $encoded_authorization_header,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $toss_response_body     = curl_exec($curl_handle);
    $toss_http_status_code  = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
    $curl_error_message     = curl_error($curl_handle);
    curl_close($curl_handle);

    if ($curl_error_message) {
        $database->rollBack();
        error_log('토스페이먼츠 통신 오류: ' . $curl_error_message);
        sendError('결제 승인 서버와 통신할 수 없습니다', 500);
    }

    $toss_response_data = json_decode($toss_response_body, true);

    if ($toss_http_status_code !== 200) {
        // 승인 실패 — 결제를 FAILED로 기록하고 종료
        $mark_failed_statement = $database->prepare("
            UPDATE payments
            SET status = 'FAILED', payment_key = :payment_key, toss_response = :toss_response
            WHERE payment_id = :payment_id
        ");
        $mark_failed_statement->bindParam(':payment_key', $received_payment_key);
        $mark_failed_statement->bindParam(':toss_response', $toss_response_body);
        $mark_failed_statement->bindParam(':payment_id', $payment_record['payment_id'], PDO::PARAM_INT);
        $mark_failed_statement->execute();

        $database->commit();

        $failure_message = $toss_response_data['message'] ?? '결제 승인에 실패했습니다';
        error_log('토스페이먼츠 승인 실패 (HTTP ' . $toss_http_status_code . '): ' . $toss_response_body);
        sendError($failure_message);
    }

    // ── 3. 결제 완료 처리 + 등록권 잔액 증가 (같은 트랜잭션) ──
    $mark_done_statement = $database->prepare("
        UPDATE payments
        SET status = 'DONE', payment_key = :payment_key, toss_response = :toss_response, confirmed_at = NOW()
        WHERE payment_id = :payment_id
    ");
    $mark_done_statement->bindParam(':payment_key', $received_payment_key);
    $mark_done_statement->bindParam(':toss_response', $toss_response_body);
    $mark_done_statement->bindParam(':payment_id', $payment_record['payment_id'], PDO::PARAM_INT);
    $mark_done_statement->execute();

    $increase_ticket_balance_statement = $database->prepare("
        INSERT INTO user_campaign_tickets (user_id, ticket_balance)
        VALUES (:user_id, :ticket_count_for_insert)
        ON DUPLICATE KEY UPDATE ticket_balance = ticket_balance + :ticket_count_for_update
    ");
    $increase_ticket_balance_statement->bindParam(':user_id', $authenticated_user_id, PDO::PARAM_INT);
    $increase_ticket_balance_statement->bindParam(':ticket_count_for_insert', $payment_record['ticket_count'], PDO::PARAM_INT);
    $increase_ticket_balance_statement->bindParam(':ticket_count_for_update', $payment_record['ticket_count'], PDO::PARAM_INT);
    $increase_ticket_balance_statement->execute();

    $database->commit();

    sendSuccess('결제가 완료되었습니다', [
        'ticket_count' => (int)$payment_record['ticket_count'],
    ]);

} catch (PDOException $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    sendError('결제 승인 처리 중 오류가 발생했습니다: ' . $exception->getMessage(), 500);
}
?>