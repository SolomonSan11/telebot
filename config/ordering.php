<?php

return [
    'menu_page_size' => max(1, min(15, (int) env('ORDERING_MENU_PAGE_SIZE', 5))),
    'currency_prefix' => env('ORDERING_CURRENCY_PREFIX', '$'),

    'kpay_display_name' => env('ORDERING_KPAY_DISPLAY_NAME', 'TestingUser'),

    'kpay_display_number' => env('ORDERING_KPAY_DISPLAY_NUMBER', '09423459867'),
];
