<?php

return [
    'menu_page_size' => max(1, min(15, (int) env('ORDERING_MENU_PAGE_SIZE', 5))),
    'currency_prefix' => env('ORDERING_CURRENCY_PREFIX', '$'),
];
