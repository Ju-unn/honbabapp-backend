<?php
/**
 * 체험단 등록권 구매 내역 조회 API  [신규]
 * GET /api/payment/history.php?page=1&limit=20
 *
 * 전자상거래법상 구매내역 확인 수단 제공을 위해
 * 인증된 사용자 본인의 결제 내역(완료/실패)을 최신순으로 반환한다.
 * READY 상태(결제창만 열고 완료하지 않은 주문)는 내역에서 제외한다.
 *
 * 응답 (성공):
 * {
 *   "success": true,
 *   "message": "구매 내역 조회 성공",
 *   "data": {
 *     "payments": [
 *       {
 *         "payment_id": 12,
 *         "order_id": "TCKT_3_a1b2...",
 *         "product_code": "TICKET_5",
 *         "order_name": "체험단 등록권 5회",
 *         "ticket_count": 5,
 *         "amount": 13500,
 *         "status": "DONE",              // DONE | FAILED
 *         "confirmed_at": "2026-07-01 12:00:00"  // FAILED면 null 가능
 *       }
 *     ],
 *     "page": 1,
 *     "limit": 20,
 *     "total_count": 3,
 *     "has_more": false
 *   }
 * }
 */

require_once '../config/database.php';
require_once '../utils/response.php';
require_once '../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('GET 요청만 허용됩니다', 405);
}

$authenticated_user_id = get_user_id_from_token();

$page  = isset($_GET['page'])  ? intval($_GET['page'])  : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

// 안전 가드 (악의적 큰 값 방지)
if ($page < 1) $page = 1;
if ($limit < 1 || $limit > 50) $limit = 20;

$database_connection = new Database();
$database = $database_connection->getConnection();

if ($database === null) {
    sendError('데이터베이스 연결에 실패했습니다', 500);
}

try {
    $count_statement = $database->prepare("
        SELECT COUNT(*) AS total
          FROM payments
         WHERE user_id = :user_id
           AND status IN ('DONE', 'FAILED')
    ");
    $count_statement->bindParam(':user_id', $authenticated_user_id, PDO::PARAM_INT);
    $count_statement->execute();
    $total_count = intval($count_statement->fetch(PDO::FETCH_ASSOC)['total']);

    $offset = ($page - 1) * $limit;

    // confirmed_at은 승인 성공(DONE) 시에만 기록된다.
    // 정렬은 payment_id DESC(생성 순서와 동일)로 안정적으로 최신순을 보장한다.
    $history_statement = $database->prepare("
        SELECT payment_id, order_id, product_code, ticket_count, amount, status, confirmed_at
          FROM payments
         WHERE user_id = :user_id
           AND status IN ('DONE', 'FAILED')
         ORDER BY payment_id DESC
         LIMIT :limit OFFSET :offset
    ");
    $history_statement->bindParam(':user_id', $authenticated_user_id, PDO::PARAM_INT);
    $history_statement->bindParam(':limit', $limit, PDO::PARAM_INT);
    $history_statement->bindParam(':offset', $offset, PDO::PARAM_INT);
    $history_statement->execute();

    // 상품명은 서버 상품 정의를 기준으로 표시한다 (상품 정의가 바뀌어도 코드 fallback)
    $ticket_product_definitions = require '../config/ticket_products.php';

    $payments = [];
    while ($row = $history_statement->fetch(PDO::FETCH_ASSOC)) {
        $product_code = $row['product_code'];
        $order_name = isset($ticket_product_definitions[$product_code])
            ? $ticket_product_definitions[$product_code]['order_name']
            : $product_code;

        $payments[] = [
            'payment_id'   => intval($row['payment_id']),
            'order_id'     => $row['order_id'],
            'product_code' => $product_code,
            'order_name'   => $order_name,
            'ticket_count' => intval($row['ticket_count']),
            'amount'       => intval($row['amount']),
            'status'       => $row['status'],
            'confirmed_at' => $row['confirmed_at'],   // null 허용
        ];
    }

    $has_more = ($page * $limit) < $total_count;

    sendSuccess('구매 내역 조회 성공', [
        'payments'    => $payments,
        'page'        => $page,
        'limit'       => $limit,
        'total_count' => $total_count,
        'has_more'    => $has_more,
    ]);

} catch (PDOException $exception) {
    sendError('구매 내역 조회 중 오류가 발생했습니다: ' . $exception->getMessage(), 500);
}
?>
