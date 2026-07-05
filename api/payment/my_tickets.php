<?php
/**
 * 내 체험단 등록권 잔액 조회 API
 * GET /api/payment/my_tickets.php
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

$authenticated_user_id = get_user_id_from_token();

$database_connection = new Database();
$database = $database_connection->getConnection();

if ($database === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $select_ticket_balance_statement = $database->prepare("
        SELECT ticket_balance FROM user_campaign_tickets WHERE user_id = :user_id
    ");
    $select_ticket_balance_statement->bindParam(':user_id', $authenticated_user_id, PDO::PARAM_INT);
    $select_ticket_balance_statement->execute();

    $ticket_balance = 0;
    if ($select_ticket_balance_statement->rowCount() > 0) {
        $ticket_row = $select_ticket_balance_statement->fetch(PDO::FETCH_ASSOC);
        $ticket_balance = (int)$ticket_row['ticket_balance'];
    }

    sendSuccess('등록권 잔액 조회 성공', [
        'ticket_balance' => $ticket_balance,
    ]);

} catch (PDOException $exception) {
    sendError('등록권 잔액 조회 중 오류가 발생했습니다: ' . $exception->getMessage(), 500);
}
?>