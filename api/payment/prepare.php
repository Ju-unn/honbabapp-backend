<?php
/**
 * 체험단 등록권 결제 준비 API
 * POST /api/payment/prepare.php
 *
 * 인증된 사용자가 구매하려는 등록권 상품 코드를 받아
 * payments 테이블에 READY 상태의 주문을 생성하고,
 * 클라이언트가 토스페이먼츠 결제창을 띄우는 데 필요한
 * order_id, amount, order_name을 반환한다.
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST 요청만 허용됩니다', 405);
}

$authenticated_user_id = get_user_id_from_token();

$request_body = json_decode(file_get_contents('php://input'), true);

if (empty($request_body['product_code'])) {
    sendError('product_code가 필요합니다');
}

$requested_product_code = trim($request_body['product_code']);

$ticket_product_definitions = require '../config/ticket_products.php';

if (!array_key_exists($requested_product_code, $ticket_product_definitions)) {
    sendError('존재하지 않는 상품입니다');
}

$selected_product = $ticket_product_definitions[$requested_product_code];

$database_connection = new Database();
$database = $database_connection->getConnection();

if ($database === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

// 주문번호 규칙(토스페이먼츠 규격: 영문 대소문자/숫자/-,_,=,.,@ 최소 6자 ~ 최대 64자)을 만족해야 한다.
$generated_order_id = 'TCKT_' . $authenticated_user_id . '_' . bin2hex(random_bytes(8));

try {
    $insert_payment_statement = $database->prepare("
        INSERT INTO payments (
            user_id, order_id, product_code, ticket_count, amount, status
        ) VALUES (
            :user_id, :order_id, :product_code, :ticket_count, :amount, 'READY'
        )
    ");
    $insert_payment_statement->bindParam(':user_id', $authenticated_user_id, PDO::PARAM_INT);
    $insert_payment_statement->bindParam(':order_id', $generated_order_id);
    $insert_payment_statement->bindParam(':product_code', $requested_product_code);
    $insert_payment_statement->bindParam(':ticket_count', $selected_product['ticket_count'], PDO::PARAM_INT);
    $insert_payment_statement->bindParam(':amount', $selected_product['amount'], PDO::PARAM_INT);
    $insert_payment_statement->execute();

    sendSuccess('결제 준비가 완료되었습니다', [
        'order_id'   => $generated_order_id,
        'amount'     => $selected_product['amount'],
        'order_name' => $selected_product['order_name'],
    ]);

} catch (PDOException $exception) {
    sendError('결제 준비 중 오류가 발생했습니다: ' . $exception->getMessage(), 500);
}
?>