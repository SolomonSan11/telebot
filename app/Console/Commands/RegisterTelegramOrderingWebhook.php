<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Telegram\Bot\Laravel\Facades\Telegram;
use Throwable;

class RegisterTelegramOrderingWebhook extends Command
{
    protected $signature = 'telegram:register-ordering-webhook';

    protected $description = 'Register HTTPS webhook with optional secret (uses APP_URL + /api/telegram/webhook)';

    public function handle(): int
    {
        $base = rtrim((string) config('app.url'), '/');
        if (! Str::startsWith($base, 'https://')) {
            $this->error('APP_URL must use https:// for Telegram webhooks.');

            return self::FAILURE;
        }

        $url = $base.'/api/telegram/webhook';

        $params = [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
        ];

        $secret = config('telegram.webhook_secret');
        if (is_string($secret) && $secret !== '') {
            $params['secret_token'] = $secret;
        }

        try {
            $ok = Telegram::setWebhook($params);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($ok) {
            $this->info('Webhook registered: '.$url);
            if (isset($params['secret_token'])) {
                $this->line('Secret token is active — TELEGRAM_WEBHOOK_SECRET must stay in .env');
            }

            return self::SUCCESS;
        }

        $this->error('Telegram rejected setWebhook — check token and URL reachability.');

        return self::FAILURE;
    }
}
