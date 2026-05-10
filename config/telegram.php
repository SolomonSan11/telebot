<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook verification (recommended in production)
    |--------------------------------------------------------------------------
    | When set, Telegram sends header X-Telegram-Bot-Api-Secret-Token matching
    | this value. Use the same secret when registering the webhook
    | (secret_token parameter). Matches irazasyed/telegram-bot-sdk WebhookCommand
    | by passing params manually or using `telegram:set-webhook` in this project.
    */
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    /*
    | Chat id(s) for order alerts (comma-separated extra list or use single id alone).
    | Example private user id: "123456789" — group/channel: "-1001234567890"
    */
    'orders_notify_chat_ids' => array_values(array_unique(array_filter(
        preg_split(
            '/\s*,\s*/',
            implode(',', array_filter([
                trim((string) env('TELEGRAM_ORDERS_NOTIFY_CHAT_ID', '')),
                trim((string) env('TELEGRAM_ORDERS_NOTIFY_CHAT_IDS', '')),
            ], static fn (string $s): bool => $s !== '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ),
        static fn (string $id): bool => $id !== ''
    ))),

    /*
    | Who may tap buttons on staff order alerts (e.g. "On the way"). Comma-separated.
    | Usernames without @ — case-insensitive. Optional numeric user ids are stricter (recommended).
    */
    'admin_usernames' => array_values(array_filter(
        array_map(
            static fn (string $s): string => strtolower(ltrim(trim($s), '@')),
            preg_split('/\s*,\s*/', (string) env('TELEGRAM_ADMIN_USERNAMES', ''), -1, PREG_SPLIT_NO_EMPTY)
        ),
        static fn (string $u): bool => $u !== ''
    )),

    'admin_user_ids' => array_values(array_unique(array_filter(
        array_map(
            static fn (string $s): int => (int) trim($s),
            preg_split('/\s*,\s*/', (string) env('TELEGRAM_ADMIN_USER_IDS', ''), -1, PREG_SPLIT_NO_EMPTY)
        ),
        static fn (int $id): bool => $id > 0
    ))),

    'bots' => [
        'mybot' => [
            'token' => env('TELEGRAM_BOT_TOKEN', 'YOUR-BOT-TOKEN'),
            'certificate_path' => env('TELEGRAM_CERTIFICATE_PATH', 'YOUR-CERTIFICATE-PATH'),
            'webhook_url' => env('TELEGRAM_WEBHOOK_URL', 'YOUR-BOT-WEBHOOK-URL'),
            'allowed_updates' => ['message', 'callback_query'],
            'commands' => [],
        ],
    ],

    'default' => 'mybot',

    'async_requests' => env('TELEGRAM_ASYNC_REQUESTS', false),

    'http_client_handler' => null,

    'base_bot_url' => null,

    'resolve_command_dependencies' => true,

    'commands' => [],

    'command_groups' => [],

    'shared_commands' => [],
];
