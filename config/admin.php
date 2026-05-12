<?php

return [
    /*
    | Shared secret for admin export URLs. Use a long random string.
    | Example: GET /admin/orders/export?token=...&period=day
    */
    'export_token' => env('ADMIN_EXPORT_TOKEN'),

    /*
    | Optional: also accept this header (useful for scripts): X-Admin-Export-Token
    */
    'export_token_header' => 'X-Admin-Export-Token',
];
