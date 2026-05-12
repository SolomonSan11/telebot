<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\Update;
use Throwable;

class TelegramOrderBot
{
    private const BTN_BROWSE = 'Browse Menu';

    private const BTN_CART = 'My Cart';

    private const BTN_CLEAR = 'Clear cart';

    private const BTN_ORDERS = 'My orders';

    private const BTN_HELP = 'Help';

    private const BTN_PLACE_ORDER = 'Place order';

    private const BTN_ORDER_REPORTS = 'Export orders';

    public function __construct(
        private readonly CheckoutOrderService $checkoutOrderService,
        private readonly AdminOrdersExcelExportService $adminOrdersExcelExportService,
    ) {}

    public function handle(Update $update): void
    {
        if ($cq = $update->callbackQuery) {
            $this->handleCallback($cq->id, $cq->from, $cq->message, (string) ($cq->data ?? ''));

            return;
        }

        $msg = $update->message;
        if (! $msg) {
            return;
        }

        $chat = $msg->chat;
        if (($chat->type ?? '') !== 'private') {
            return;
        }

        $from = $msg->from;
        if (! $from || ($from->isBot ?? false)) {
            return;
        }

        $chatId = (string) $chat->id;
        $textRaw = $msg->text;
        $text = is_string($textRaw) ? trim($textRaw) : '';

        if ($text === '' || $text === '0') {
            return;
        }

        $user = $this->upsertTelegramUser($from);
        $user->purgeStaleCartProducts();

        if (preg_match('/^\/start(\s|$)/i', $text)) {
            $this->sendWelcome($chatId, $user);

            return;
        }

        if (preg_match('/^\/myid$/i', $text)) {
            if (! $this->telegramUserIsStaff($from)) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'That isn’t available here. Open Help for what you can do.',
                    'reply_markup' => $this->replyKeyboardMarkup($user),
                ]);

                return;
            }

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Your Telegram user ID: <code>'.$from->id.'</code>',
                'parse_mode' => 'HTML',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);

            return;
        }

        if (preg_match('/^\/export\s+(day|week|month)\s*$/i', $text, $m)) {
            if (! $this->telegramUserIsStaff($from)) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'That isn’t available here. Open Help for what you can do.',
                    'reply_markup' => $this->replyKeyboardMarkup($user),
                ]);

                return;
            }
            $this->sendStaffExcelExport($chatId, $user, strtolower($m[1]));

            return;
        }

        if (preg_match('/^\/export\s+(\d{4}-\d{2}-\d{2})\s+(\d{4}-\d{2}-\d{2})\s*$/i', $text, $m)) {
            if (! $this->telegramUserIsStaff($from)) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'That isn’t available here. Open Help for what you can do.',
                    'reply_markup' => $this->replyKeyboardMarkup($user),
                ]);

                return;
            }
            $this->sendStaffExcelExport($chatId, $user, 'range', $m[1], $m[2]);

            return;
        }

        if (preg_match('/^\/(help|menu|cart|orders|clear|checkout)$/i', $text, $m)) {
            match (strtolower($m[1])) {
                'help' => $this->sendHelp($chatId, $user),
                'menu' => $this->sendBrowseMenuFresh($chatId, 0),
                'cart' => $this->sendCartSummary($chatId, $user),
                'checkout' => $this->checkoutFromKeyboard($chatId, $user),
                'orders' => $this->sendOrderHistory($chatId, $user),
                'clear' => $this->clearCartReply($chatId, $user),
            };

            return;
        }

        $norm = strtolower($text);
        match ($norm) {
            strtolower(self::BTN_BROWSE) => $this->sendBrowseMenuFresh($chatId, 0),
            strtolower(self::BTN_CART) => $this->sendCartSummary($chatId, $user),
            strtolower(self::BTN_CLEAR) => $this->clearCartReply($chatId, $user),
            strtolower(self::BTN_ORDERS) => $this->sendOrderHistory($chatId, $user),
            strtolower(self::BTN_HELP) => $this->sendHelp($chatId, $user),
            strtolower(self::BTN_PLACE_ORDER) => $this->checkoutFromKeyboard($chatId, $user),
            strtolower(self::BTN_ORDER_REPORTS) => $this->sendOrderReportsPicker($chatId, $user, $from),
            default => Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Use the menu below, or open Help.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]),
        };
    }

    private function upsertTelegramUser($from): TelegramUser
    {
        $nameParts = array_filter([
            $from->firstName ?? null,
            $from->lastName ?? null,
        ]);
        $name = $nameParts !== [] ? implode(' ', $nameParts) : null;

        $user = TelegramUser::query()->firstOrCreate(
            ['telegram_id' => $from->id],
            [
                'name' => $name,
                'username' => $from->username ?? null,
                'shopping_cart' => [],
            ],
        );

        $user->forceFill([
            'name' => $name,
            'username' => $from->username ?? null,
        ]);
        if ($user->isDirty()) {
            $user->save();
        }

        return $user;
    }

    private function handleCallback(string $callbackQueryId, $from, $message, string $data): void
    {
        if (preg_match('/^tw:(\d+)$/', $data, $m)) {
            $this->handleAdminOrderOnTheWay($callbackQueryId, $from, $message, (int) $m[1]);

            return;
        }

        if (preg_match('/^ex:(day|week|month)$/', $data, $m)) {
            $this->handleStaffExportCallback($callbackQueryId, $from, $message, strtolower($m[1]));

            return;
        }

        $this->answerCallback($callbackQueryId);

        if (! $message || ! $from || ($from->isBot ?? false)) {
            return;
        }

        $chat = $message->chat;
        if (($chat->type ?? '') !== 'private') {
            return;
        }

        $chatId = (string) $chat->id;
        $messageId = $message->messageId;

        $user = $this->upsertTelegramUser($from);
        $user->purgeStaleCartProducts();

        if ($data === 'noop') {
            return;
        }

        if ($data === 'vc') {
            $user->refresh();
            $this->sendCartSummary($chatId, $user);

            return;
        }

        try {
            if (preg_match('/^l:(\d+)$/', $data, $m)) {
                $this->editProductList($chatId, (int) $messageId, (int) $m[1]);

                return;
            }

            if (preg_match('/^d:(\d+):(\d+)$/', $data, $m)) {
                $this->editProductDetail($chatId, (int) $messageId, (int) $m[1], (int) $m[2]);

                return;
            }

            if (preg_match('/^a:(\d+)$/', $data, $m)) {
                $this->addLine($user, (int) $m[1]);
                $this->editProductDetailFromProductId($chatId, (int) $messageId, (int) $m[1], $user);

                return;
            }

            if (preg_match('/^r:(\d+)$/', $data, $m)) {
                $this->removeLineUnit($user, (int) $m[1]);
                $this->editProductDetailFromProductId($chatId, (int) $messageId, (int) $m[1], $user);

                return;
            }

            if (preg_match('/^rq:(\d+)$/', $data, $m)) {
                $this->clearLine($user, (int) $m[1]);
                $user->refresh();
                $this->editCartMessage($chatId, (int) $messageId, $user);

                return;
            }

            if ($data === 'cl') {
                $user->clearCart();
                $this->safeEditMessage($chatId, (int) $messageId, 'Your cart is empty.', ['inline_keyboard' => []], $user);

                return;
            }

            if ($data === 'cf') {
                $order = $this->checkoutAndAlertStaff($user);
                $this->safeEditMessage(
                    $chatId,
                    (int) $messageId,
                    $this->orderConfirmedText($order),
                    ['inline_keyboard' => []],
                    $user
                );
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Anything else we can help with?',
                    'reply_markup' => $this->replyKeyboardMarkup($user),
                ]);

                return;
            }
        } catch (InvalidArgumentException $e) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $e->getMessage(),
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);
        } catch (Throwable $e) {
            Log::error('telegram_order_bot', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Something went wrong. Please try again.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);
        }
    }

    /**
     * Who receives NEW ORDER Telegram alerts: explicit notify ids + admin ids + usernames.
     * Usernames resolve to numeric chat ids via telegram_users (after that person has messaged the bot) or getChat(@handle).
     */
    private function orderAlertRecipientChatIds(): array
    {
        $seen = [];
        $out = [];

        $push = function ($id) use (&$seen, &$out): void {
            $k = trim((string) $id);
            if ($k === '' || isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $out[] = $id;
        };

        foreach (config('telegram.orders_notify_chat_ids', []) as $id) {
            $push($id);
        }

        foreach (config('telegram.admin_user_ids', []) as $id) {
            $push($id);
        }

        foreach (config('telegram.admin_usernames', []) as $u) {
            if ($u === '') {
                continue;
            }
            $match = TelegramUser::query()
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($u)])
                ->value('telegram_id');
            if ($match !== null) {
                $push((string) $match);

                continue;
            }
            $push('@'.$u);
        }

        return $out;
    }

    /**
     * Telegram accepts integer chat ids for DMs; @username is unreliable. Prefer DB or getChat().
     *
     * @return string|int
     */
    private function resolveOrderNotifyChatTarget(string|int $raw): string|int
    {
        $s = trim((string) $raw);

        if ($s === '') {
            return $raw;
        }

        if (preg_match('/^-?\d+$/', $s)) {
            return strlen($s) >= 12 ? $s : (int) $s;
        }

        if (str_starts_with(mb_strtolower($s), '@')) {
            $handle = ltrim($s, '@');
            $fromDb = TelegramUser::query()
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($handle)])
                ->value('telegram_id');
            if ($fromDb !== null) {
                return (string) $fromDb;
            }

            try {
                $chat = Telegram::getChat(['chat_id' => $s]);

                return $chat->id;
            } catch (Throwable $e) {
                Log::notice('telegram_getchat_resolve_failed', [
                    'chat' => $s,
                    'message' => $e->getMessage(),
                ]);

                return $s;
            }
        }

        return $raw;
    }

    private function isStaffByTelegramIdentity(int $telegramId, ?string $username): bool
    {
        if ($telegramId <= 0) {
            return false;
        }

        foreach (config('telegram.admin_user_ids', []) as $id) {
            if ($telegramId === (int) $id) {
                return true;
            }
        }

        $un = strtolower((string) ($username ?? ''));

        return $un !== '' && in_array($un, config('telegram.admin_usernames', []), true);
    }

    private function telegramActorIsStaff(TelegramUser $user): bool
    {
        return $this->isStaffByTelegramIdentity((int) $user->telegram_id, $user->username);
    }

    private function telegramUserIsStaff($from): bool
    {
        if (! $from || ($from->isBot ?? false)) {
            return false;
        }

        return $this->isStaffByTelegramIdentity((int) $from->id, $from->username ?? null);
    }

    private function handleAdminOrderOnTheWay(string $callbackQueryId, $from, $message, int $orderId): void
    {
        if (! $message) {
            return;
        }

        if (! $this->telegramUserIsStaff($from)) {
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => 'You can’t update this order.',
                    'show_alert' => true,
                ]);
            } catch (Throwable $e) {
                Log::notice('telegram_answer_callback_failed', ['message' => $e->getMessage()]);
            }

            return;
        }

        $order = Order::query()->with('telegramUser')->find($orderId);
        if (! $order || ! $order->telegramUser) {
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => 'We couldn’t find that order.',
                    'show_alert' => true,
                ]);
            } catch (Throwable $e) {
                Log::notice('telegram_answer_callback_failed', ['message' => $e->getMessage()]);
            }

            return;
        }

        if ($order->status !== 'pending') {
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => 'This order was already updated.',
                    'show_alert' => true,
                ]);
            } catch (Throwable $e) {
                Log::notice('telegram_answer_callback_failed', ['message' => $e->getMessage()]);
            }

            return;
        }

        $order->update(['status' => 'on_the_way']);

        $customerChatId = (string) $order->telegramUser->telegram_id;
        $customerText = 'Your order #'.$order->id.' is on its way. Total '.$this->fmtMoney((string) $order->total).'. Thanks for your order.';

        $customerReachable = true;

        try {
            Telegram::sendMessage([
                'chat_id' => $customerChatId,
                'text' => $customerText,
            ]);
        } catch (Throwable $e) {
            $customerReachable = false;
            Log::warning('telegram_customer_on_the_way_failed', [
                'message' => $e->getMessage(),
                'order_id' => $order->id,
                'telegram_user_id' => $order->telegram_user_id,
            ]);
        }

        try {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => $customerReachable ? 'Buyer notified.' : 'Saved. We couldn’t message the buyer in this chat.',
                'show_alert' => ! $customerReachable,
            ]);
        } catch (Throwable $e) {
            Log::notice('telegram_answer_callback_failed', ['message' => $e->getMessage()]);
        }

        $adminChatId = (string) $message->chat->id;
        $messageId = (int) $message->messageId;
        $original = (string) ($message->text ?? '');
        $suffix = $customerReachable
            ? "\n\nOut for delivery · Buyer notified"
            : "\n\nOut for delivery · Buyer message not delivered (they may need to open this bot)";

        try {
            Telegram::editMessageText([
                'chat_id' => $adminChatId,
                'message_id' => $messageId,
                'text' => $original.$suffix,
                'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $e) {
            Log::notice('telegram_staff_edit_after_dispatch_failed', ['message' => $e->getMessage()]);
        }
    }

    private function notifyStaff(Order $order): void
    {
        $chatIds = $this->orderAlertRecipientChatIds();
        if ($chatIds === []) {
            Log::warning('telegram_orders_notify_skipped', [
                'reason' => 'no notify chat ids and no TELEGRAM_ADMIN_USERNAMES / TELEGRAM_ADMIN_USER_IDS',
                'order_id' => $order->id,
            ]);

            return;
        }

        $order->loadMissing(['telegramUser', 'items.product']);

        $lines = [];
        foreach ($order->items as $item) {
            $pName = $item->relationLoaded('product') && $item->product ? $item->product->name : 'Line item';
            $lines[] = '• '.$pName.' × '.$item->qty.' @ '.$this->fmtMoney((string) $item->price);
        }

        $who = ($order->telegramUser->name ?? 'Guest')
            .($order->telegramUser->username ? ' (@'.$order->telegramUser->username.')' : '');

        $text = 'New order #'.$order->id."\n".$who."\n"
            .'Total '.$this->fmtMoney((string) $order->total)."\n\n"
            .implode("\n", $lines)
            ."\n\nWhen it leaves, tap below:";

        $markup = json_encode([
            'inline_keyboard' => [
                [['text' => 'Out for delivery', 'callback_data' => 'tw:'.$order->id]],
            ],
        ], JSON_THROW_ON_ERROR);

        foreach ($chatIds as $notifyChatId) {
            try {
                $target = $this->resolveOrderNotifyChatTarget($notifyChatId);
                Telegram::sendMessage([
                    'chat_id' => $target,
                    'text' => $text,
                    'reply_markup' => $markup,
                ]);
                Log::info('telegram_order_alert_sent', [
                    'order_id' => $order->id,
                    'to_raw' => $notifyChatId,
                    'to_resolved' => $target,
                ]);
            } catch (Throwable $e) {
                Log::warning('telegram_staff_notify_failed', [
                    'message' => $e->getMessage(),
                    'chat_id_raw' => $notifyChatId,
                    'order_id' => $order->id,
                    'hint' => 'Verify notification targets and that the bot can reach buyers.',
                ]);
            }
        }
    }

    /** @throws InvalidArgumentException */
    private function checkoutAndAlertStaff(TelegramUser $customer): Order
    {
        $order = $this->checkoutOrderService->checkout($customer);
        $this->notifyStaff($order);

        return $order->fresh(['items.product', 'telegramUser']);
    }

    private function orderConfirmedText(Order $order): string
    {
        return 'Order #'.$order->id.' is in. Total '.$this->fmtMoney((string) $order->total)."\n"
            .'We’ll follow up shortly.';
    }

    private function checkoutFromKeyboard(string $chatId, TelegramUser $user): void
    {
        $user->refresh();

        try {
            $order = $this->checkoutAndAlertStaff($user);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->orderConfirmedText($order),
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);
        } catch (InvalidArgumentException $e) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $e->getMessage(),
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);
        } catch (Throwable $e) {
            Log::error('telegram_order_checkout_keyboard', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'We couldn’t place that order. Try again in a moment.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);
        }
    }

    private function answerCallback(string $callbackQueryId): void
    {
        try {
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
        } catch (Throwable $e) {
            Log::notice('telegram_answer_callback_failed', ['message' => $e->getMessage()]);
        }
    }

    private function sendWelcome(string $chatId, TelegramUser $user): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "Hi — you can order right in this chat.\n\nUse Menu to browse, Cart to review, then Place order when you’re ready.",
            'reply_markup' => $this->replyKeyboardMarkup($user),
        ]);
    }

    private function sendHelp(string $chatId, TelegramUser $user): void
    {
        $lines = [
            'Help',
            '',
            '/start — Home',
            '/menu — Menu',
            '/cart — Cart',
            '/checkout — Place order (same as the button)',
            '/orders — Order history',
            '/clear — Empty cart',
            '/help — Help',
        ];

        if ($this->telegramActorIsStaff($user)) {
            $lines[] = '';
            $lines[] = 'Team';
            $lines[] = '• '.self::BTN_ORDER_REPORTS.' — or /export day, week, month';
            $lines[] = '• Custom range — /export YYYY-MM-DD YYYY-MM-DD';
            $lines[] = '• /myid — Your Telegram user ID';
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
            'reply_markup' => $this->replyKeyboardMarkup($user),
        ]);
    }

    private function clearCartReply(string $chatId, TelegramUser $user): void
    {
        $user->clearCart();
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Done. Your cart is empty.',
            'reply_markup' => $this->replyKeyboardMarkup($user),
        ]);
    }

    private function sendBrowseMenuFresh(string $chatId, int $page): void
    {
        $keyboard = $this->buildProductListKeyboard($page);
        $caption = $this->buildProductListCaption($page);

        $params = [
            'chat_id' => $chatId,
            'text' => $caption,
        ];

        if ($keyboard !== []) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard,
            ], JSON_THROW_ON_ERROR);
        }

        Telegram::sendMessage($params);
    }

    private function sendCartSummary(string $chatId, TelegramUser $user): void
    {
        $text = $this->formatCartText($user);

        $rows = $this->buildCartKeyboardRows($user);
        $payload = $rows !== []
            ? json_encode(['inline_keyboard' => $rows], JSON_THROW_ON_ERROR)
            : $this->replyKeyboardMarkup($user);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $payload,
        ]);
    }

    private function editCartMessage(string $chatId, int $messageId, TelegramUser $user): void
    {
        $text = $this->formatCartText($user);
        $rows = $this->buildCartKeyboardRows($user);
        $this->safeEditMessage($chatId, $messageId, $text, ['inline_keyboard' => $rows], $user);
    }

    private function buildCartKeyboardRows(TelegramUser $user): array
    {
        $linesMap = $user->normalizedCartLines();
        if ($linesMap === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', array_keys($linesMap))
            ->where('active', true)
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($linesMap as $pid => $qty) {
            $p = $products->get((int) $pid);
            $label = $p ? ($this->shortenInlineLabel($p->name).' × '.(int) $qty) : ('item #'.$pid.' × '.(int) $qty);
            $rows[] = [
                [
                    'text' => 'Remove · '.$label,
                    'callback_data' => 'rq:'.(int) $pid,
                ],
            ];
        }

        $rows[] = [['text' => self::BTN_PLACE_ORDER, 'callback_data' => 'cf']];
        $rows[] = [['text' => 'Empty cart', 'callback_data' => 'cl']];

        return $rows;
    }

    private function formatCartText(TelegramUser $user): string
    {
        $linesMap = $user->normalizedCartLines();

        if ($linesMap === []) {
            return 'Nothing in your cart yet. Open Menu to add items.';
        }

        $products = Product::query()
            ->whereIn('id', array_keys($linesMap))
            ->where('active', true)
            ->get()
            ->keyBy('id');

        $chunks = [];
        $subtotal = '0.00';

        foreach ($linesMap as $pid => $qty) {
            $p = $products->get((int) $pid);
            if (! $p) {
                continue;
            }
            $q = (int) $qty;
            $line = bcmul((string) $p->price, (string) $q, 2);
            $subtotal = bcadd($subtotal, $line, 2);
            $chunks[] = '• '.$p->name.' × '.$q.' — '.$this->fmtMoney($line);
        }

        if ($chunks === []) {
            return 'Some items are no longer available. Your cart was updated — open Menu to continue.';
        }

        return "Cart\n\n".implode("\n", $chunks)."\n\nSubtotal ".$this->fmtMoney($subtotal)
            ."\n\nUse Place order when you’re ready.";
    }

    private function sendOrderHistory(string $chatId, TelegramUser $user): void
    {
        $orders = Order::query()
            ->where('telegram_user_id', $user->id)
            ->orderByDesc('id')
            ->limit(8)
            ->with('items.product')
            ->get();

        if ($orders->isEmpty()) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'No orders yet. Open Menu to get started.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);

            return;
        }

        $parts = [];
        foreach ($orders as $order) {
            $tiny = [];
            foreach ($order->items as $item) {
                $n = $item->relationLoaded('product') && $item->product ? $item->product->name : '#'.$item->product_id;
                $tiny[] = $n.' × '.$item->qty;
            }
            $parts[] = '#'.$order->id.' • '.$order->status.' • '.$this->fmtMoney((string) $order->total)
                ."\n".implode(', ', $tiny);
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => implode("\n\n", $parts),
            'reply_markup' => $this->replyKeyboardMarkup($user),
        ]);
    }

    private function editProductList(string $chatId, int $messageId, int $page): void
    {
        $keyboard = $this->buildProductListKeyboard($page);
        $caption = $this->buildProductListCaption($page);
        $this->safeEditMessage($chatId, $messageId, $caption, ['inline_keyboard' => $keyboard]);
    }

    private function editProductDetail(string $chatId, int $messageId, int $productId, int $page): void
    {
        $p = Product::query()->where('active', true)->find($productId);
        if (! $p) {
            $this->editProductList($chatId, $messageId, $page);

            return;
        }

        $keyboard = [
            [['text' => 'Add (+1)', 'callback_data' => 'a:'.$productId]],
            [['text' => 'Remove (−1)', 'callback_data' => 'r:'.$productId]],
            [['text' => 'Open cart', 'callback_data' => 'vc']],
            [['text' => 'Back to menu', 'callback_data' => 'l:'.$page]],
        ];

        $this->safeEditMessage($chatId, $messageId, $this->formatProductDetail($p), ['inline_keyboard' => $keyboard]);
    }

    private function editProductDetailFromProductId(string $chatId, int $messageId, int $productId, TelegramUser $user): void
    {
        $p = Product::query()->where('active', true)->find($productId);
        if (! $p) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'That item isn’t available anymore.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);

            return;
        }

        $page = $this->productPageContainingId($productId);
        $inCart = $user->cartQuantity($productId);

        $lines = [$this->formatProductDetail($p)];
        $lines[] = 'In cart: '.$inCart.' · Stock: '.$p->stock;

        $keyboard = [
            [['text' => 'Add (+1)', 'callback_data' => 'a:'.$productId]],
            [['text' => 'Remove (−1)', 'callback_data' => 'r:'.$productId]],
            [['text' => 'Open cart', 'callback_data' => 'vc']],
            [['text' => 'Back to menu', 'callback_data' => 'l:'.$page]],
        ];

        $this->safeEditMessage($chatId, $messageId, implode("\n\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function productPageContainingId(int $productId): int
    {
        $per = config('ordering.menu_page_size', 5);
        $positions = Product::query()
            ->where('active', true)
            ->orderBy('id')
            ->pluck('id')
            ->values();
        $index = $positions->search(static fn ($id) => (int) $id === $productId);
        if ($index === false) {
            return 0;
        }

        return intdiv((int) $index, $per);
    }

    private function formatProductDetail(Product $p): string
    {
        $chunks = [$p->name, 'Price '.$this->fmtMoney((string) $p->price), 'Stock: '.$p->stock];
        $desc = $p->description ?? null;
        if (is_string($desc) && $desc !== '') {
            $chunks[] = trim($desc);
        }

        return implode("\n", $chunks);
    }

    private function buildProductListCaption(int $page): string
    {
        $total = Product::query()->where('active', true)->count();
        if ($total === 0) {
            return 'Nothing on the menu right now. Check back soon.';
        }

        $per = config('ordering.menu_page_size', 5);
        $pages = max(1, (int) ceil($total / $per));
        $humanPage = min($pages, $page + 1);

        return 'Menu · Page '.$humanPage.' of '.$pages;
    }

    private function buildProductListKeyboard(int $page): array
    {
        $per = config('ordering.menu_page_size', 5);

        $totalCount = Product::query()->where('active', true)->count();
        if ($totalCount === 0) {
            return [];
        }

        $products = Product::query()
            ->where('active', true)
            ->orderBy('id')
            ->skip($page * $per)
            ->take($per)
            ->get();

        $rows = [];

        foreach ($products as $p) {
            $label = '+ '.$this->shortenInlineLabel($p->name).' — '.$this->fmtMoney((string) $p->price);
            $rows[] = [
                ['text' => $label, 'callback_data' => 'd:'.$p->id.':'.$page],
            ];
        }

        $total = $totalCount;
        $pages = max(1, (int) ceil($total / $per));

        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => 'Prev', 'callback_data' => 'l:'.($page - 1)];
        }

        $navRow[] = ['text' => 'Page '.($page + 1).' / '.$pages, 'callback_data' => 'noop'];

        if ($page < $pages - 1) {
            $navRow[] = ['text' => 'Next', 'callback_data' => 'l:'.($page + 1)];
        }

        if ($navRow !== []) {
            $rows[] = $navRow;
        }

        $rows[] = [['text' => 'Open cart', 'callback_data' => 'vc']];

        return $rows;
    }

    private function shortenInlineLabel(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?: 'Item';

        return mb_strlen($name) <= 42 ? $name : (mb_substr($name, 0, 39).'…');
    }

    private function addLine(TelegramUser $user, int $productId): void
    {
        $p = Product::query()->where('active', true)->findOrFail($productId);
        $user->incrementCart($p, 1);
    }

    private function removeLineUnit(TelegramUser $user, int $productId): void
    {
        $user->removeOne($productId);
    }

    private function clearLine(TelegramUser $user, int $productId): void
    {
        $user->clearLine($productId);
    }

    private function safeEditMessage(string $chatId, int $messageId, string $text, array $keyboard, ?TelegramUser $actor = null): void
    {
        try {
            Telegram::editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard['inline_keyboard'] ?? []], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $e) {
            Log::notice('telegram_edit_fallback', ['message' => $e->getMessage()]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard['inline_keyboard'] ?? []], JSON_THROW_ON_ERROR),
            ]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Here are your shortcuts:',
                'reply_markup' => $this->replyKeyboardMarkup($actor),
            ]);
        }
    }

    private function sendOrderReportsPicker(string $chatId, TelegramUser $user, $from): void
    {
        if (! $this->telegramUserIsStaff($from)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'You don’t have access to exports.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);

            return;
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Choose a period. For a custom range: /export 2026-01-01 2026-01-31',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'Today', 'callback_data' => 'ex:day'],
                        ['text' => 'This week', 'callback_data' => 'ex:week'],
                        ['text' => 'This month', 'callback_data' => 'ex:month'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function handleStaffExportCallback(string $callbackQueryId, $from, $message, string $period): void
    {
        if (! $message || ! $from || ($from->isBot ?? false)) {
            return;
        }

        if (! $this->telegramUserIsStaff($from)) {
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => 'You don’t have access to this.',
                    'show_alert' => true,
                ]);
            } catch (Throwable $e) {
                Log::notice('telegram_answer_callback_failed', ['message' => $e->getMessage()]);
            }

            return;
        }

        $this->answerCallback($callbackQueryId);

        $chat = $message->chat;
        if (($chat->type ?? '') !== 'private') {
            return;
        }

        $chatId = (string) $chat->id;
        $user = $this->upsertTelegramUser($from);

        $this->sendStaffExcelExport($chatId, $user, $period);
    }

    private function sendStaffExcelExport(string $chatId, TelegramUser $user, string $period, ?string $rangeFrom = null, ?string $rangeTo = null): void
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Exports aren’t available at the moment. If this keeps happening, contact support.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);

            return;
        }

        $path = null;
        try {
            $file = $this->adminOrdersExcelExportService->writeXlsxToTempFile($period, $rangeFrom, $rangeTo);
            $path = $file['path'];

            Telegram::sendDocument([
                'chat_id' => $chatId,
                'document' => InputFile::create($path, $file['filename']),
                'caption' => 'Order export',
            ]);
        } catch (InvalidArgumentException $e) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $e->getMessage(),
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);
        } catch (Throwable $e) {
            Log::error('telegram_staff_excel_export', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'That export didn’t go through. Try again shortly.',
                'reply_markup' => $this->replyKeyboardMarkup($user),
            ]);
        } finally {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function fmtMoney(string $amount): string
    {
        return config('ordering.currency_prefix').number_format((float) $amount, 2, '.', '');
    }

    private function replyKeyboardMarkup(?TelegramUser $actor = null): string
    {
        $keyboard = [
            [['text' => self::BTN_BROWSE]],
            [['text' => self::BTN_CART], ['text' => self::BTN_CLEAR]],
            [['text' => self::BTN_PLACE_ORDER]],
            [['text' => self::BTN_ORDERS], ['text' => self::BTN_HELP]],
        ];

        if ($actor && $this->telegramActorIsStaff($actor)) {
            $keyboard[] = [['text' => self::BTN_ORDER_REPORTS]];
        }

        return json_encode([
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
        ]);
    }
}
