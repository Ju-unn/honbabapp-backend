<?php
/**
 * 체험단 등록권 상품 정의
 *
 * 결제 금액은 반드시 이 서버 상수를 기준으로 계산한다.
 * 클라이언트가 전달하는 금액은 절대 신뢰하지 않는다(위변조 방지).
 */

return [
    'TICKET_1' => [
        'ticket_count' => 1,
        'amount'       => 3000,
        'order_name'   => '체험단 등록권 1회',
    ],
    'TICKET_5' => [
        'ticket_count' => 5,
        'amount'       => 13500,
        'order_name'   => '체험단 등록권 5회',
    ],
    'TICKET_10' => [
        'ticket_count' => 10,
        'amount'       => 26000,
        'order_name'   => '체험단 등록권 10회',
    ],
];