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
    | Optional chat id (user or group) to receive plain-text order summaries.
    */
    'orders_notify_chat_id' => env('TELEGRAM_ORDERS_NOTIFY_CHAT_ID'),

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
