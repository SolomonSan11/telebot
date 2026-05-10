<?php

namespace App\Http\Controllers;

use App\Services\TelegramOrderBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Update;
use Throwable;

class TelegramController extends Controller
{
    public function webhook(Request $request, TelegramOrderBot $bot)
    {
        $data = json_decode($request->getContent(), true);
        if (! is_array($data)) {
            return response()->json(['ok' => true]);
        }

        try {
            $bot->handle(new Update($data));
        } catch (Throwable $e) {
            Log::error('telegram_webhook_failed', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
