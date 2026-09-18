<?php

return [
    'auth_login' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUTH_LOGIN', 5),
        'decay_seconds' => 60,
    ],
    'auth_register' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUTH_REGISTER', 3),
        'decay_seconds' => 60,
    ],
    'auth_verification' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUTH_VERIFICATION', 10),
        'decay_seconds' => 60,
    ],
    'auth_forgot_password' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUTH_PASSWORD', 3),
        'decay_seconds' => 60,
    ],
    'auth_reset_password' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUTH_RESET_PASSWORD', 5),
        'decay_seconds' => 60,
    ],
    'auth_refresh' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUTH_REFRESH', 30),
        'decay_seconds' => 60,
    ],
    'flash_sale_purchase' => [
        'max_attempts' => (int) env('RATE_LIMIT_FLASH_SALE_PURCHASE', 5),
        'decay_seconds' => 60,
    ],
    'cart_buy_now' => [
        'max_attempts' => (int) env('RATE_LIMIT_CART_BUY_NOW', 10),
        'decay_seconds' => 60,
    ],
    'order_create' => [
        'max_attempts' => (int) env('RATE_LIMIT_ORDER_CREATE', 10),
        'decay_seconds' => 60,
    ],
    'payment_create' => [
        'max_attempts' => (int) env('RATE_LIMIT_PAYMENT_CREATE', 5),
        'decay_seconds' => 60,
    ],
    'payment_webhook' => [
        'max_attempts' => (int) env('RATE_LIMIT_PAYMENT_WEBHOOK', 60),
        'decay_seconds' => 60,
    ],
];
