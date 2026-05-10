<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('telegram.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            return $next($request);
        }

        $header = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (! is_string($header) || ! hash_equals($secret, $header)) {
            abort(403, 'Invalid webhook secret.');
        }

        return $next($request);
    }
}
