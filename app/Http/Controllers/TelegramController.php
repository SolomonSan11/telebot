<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramController extends Controller
{
    public function webhook()
    {
        $update = Telegram::getWebhookUpdate();

        $message = $update->getMessage();

        if (!$message)
            return response()->json();

        $chatId = $message->getChat()->getId();
        $text = $message->getText();

        if ($text === '/start') {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Welcome to ordering bot",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => 'Browse Menu']]
                    ],
                    'resize_keyboard' => true
                ])
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
