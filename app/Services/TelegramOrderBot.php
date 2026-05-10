<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

    public function __construct(
        private readonly CheckoutOrderService $checkoutOrderService,
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
            $this->sendWelcome($chatId);

            return;
        }

        if (preg_match('/^\/(help|menu|cart|orders|clear|checkout)$/i', $text, $m)) {
            match (strtolower($m[1])) {
                'help' => $this->sendHelp($chatId),
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
            strtolower(self::BTN_HELP) => $this->sendHelp($chatId),
            strtolower(self::BTN_PLACE_ORDER) => $this->checkoutFromKeyboard($chatId, $user),
            default => Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Use the menu buttons or tap /help for commands.',
                'reply_markup' => $this->replyKeyboardMarkup(),
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

        return TelegramUser::query()->firstOrCreate(
            ['telegram_id' => $from->id],
            [
                'name' => $name,
                'username' => $from->username ?? null,
                'shopping_cart' => [],
            ],
        );
    }

    private function handleCallback(string $callbackQueryId, $from, $message, string $data): void
    {
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
                $this->safeEditMessage($chatId, (int) $messageId, 'Your cart has been emptied.', ['inline_keyboard' => []]);

                return;
            }

            if ($data === 'cf') {
                $order = $this->checkoutAndAlertStaff($user);
                $this->safeEditMessage(
                    $chatId,
                    (int) $messageId,
                    $this->orderConfirmedText($order),
                    ['inline_keyboard' => []]
                );
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Anything else?',
                    'reply_markup' => $this->replyKeyboardMarkup(),
                ]);

                return;
            }
        } catch (InvalidArgumentException $e) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $e->getMessage(),
                'reply_markup' => $this->replyKeyboardMarkup(),
            ]);
        } catch (Throwable $e) {
            Log::error('telegram_order_bot', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Something went wrong. Please try again in a moment.',
                'reply_markup' => $this->replyKeyboardMarkup(),
            ]);
        }
    }

    private function notifyStaff(Order $order): void
    {
        $chatIds = config('telegram.orders_notify_chat_ids', []);
        if (! is_array($chatIds) || $chatIds === []) {
            Log::warning('telegram_orders_notify_skipped', [
                'reason' => 'no TELEGRAM_ORDERS_NOTIFY_CHAT_ID / TELEGRAM_ORDERS_NOTIFY_CHAT_IDS configured',
                'order_id' => $order->id,
            ]);

            return;
        }

        $order->loadMissing(['telegramUser', 'items.product']);

        $lines = [];
        foreach ($order->items as $item) {
            $pName = $item->relationLoaded('product') && $item->product ? $item->product->name : 'unknown';
            $lines[] = '• '.$pName.' × '.$item->qty.' @ '.$this->fmtMoney((string) $item->price);
        }

        $who = ($order->telegramUser->name ?? 'Customer')
            .' (@'.($order->telegramUser->username ?? 'none').')';

        $text = "NEW ORDER #{$order->id}\n{$who}\n"
            .'Total '.$this->fmtMoney((string) $order->total)."\n\n"
            .implode("\n", $lines);

        foreach ($chatIds as $notifyChatId) {
            try {
                Telegram::sendMessage([
                    'chat_id' => $notifyChatId,
                    'text' => $text,
                ]);
            } catch (Throwable $e) {
                Log::warning('telegram_staff_notify_failed', [
                    'message' => $e->getMessage(),
                    'chat_id' => $notifyChatId,
                    'order_id' => $order->id,
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
        return 'Order #'.$order->id.' received. Total: '.$this->fmtMoney((string) $order->total)."\n"
            .'Thank you — we will confirm shortly.';
    }

    private function checkoutFromKeyboard(string $chatId, TelegramUser $user): void
    {
        $user->refresh();

        try {
            $order = $this->checkoutAndAlertStaff($user);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->orderConfirmedText($order),
                'reply_markup' => $this->replyKeyboardMarkup(),
            ]);
        } catch (InvalidArgumentException $e) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $e->getMessage(),
                'reply_markup' => $this->replyKeyboardMarkup(),
            ]);
        } catch (Throwable $e) {
            Log::error('telegram_order_checkout_keyboard', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Could not complete the order right now. Please try again shortly.',
                'reply_markup' => $this->replyKeyboardMarkup(),
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

    private function sendWelcome(string $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "Welcome! Order here through this chat.\n\nTap «".self::BTN_BROWSE.'» to shop, '
                .self::BTN_PLACE_ORDER.' when you are ready, or '
                .self::BTN_CART.' to review lines first.',
            'reply_markup' => $this->replyKeyboardMarkup(),
        ]);
    }

    private function sendHelp(string $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => implode("\n", [
                '/start — home & keyboard',
                '/menu — open the catalogue',
                '/cart — show cart',
                '/checkout — '.self::BTN_PLACE_ORDER.' (keyboard has the same button)',
                '/orders — your recent orders',
                '/clear — empty cart',
                '/help — this message',
            ]),
            'reply_markup' => $this->replyKeyboardMarkup(),
        ]);
    }

    private function clearCartReply(string $chatId, TelegramUser $user): void
    {
        $user->clearCart();
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Cart cleared.',
            'reply_markup' => $this->replyKeyboardMarkup(),
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
            : $this->replyKeyboardMarkup();

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
        $this->safeEditMessage($chatId, $messageId, $text, ['inline_keyboard' => $rows]);
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
                    'text' => '🗑 '.$label,
                    'callback_data' => 'rq:'.(int) $pid,
                ],
            ];
        }

        $rows[] = [['text' => self::BTN_PLACE_ORDER, 'callback_data' => 'cf']];
        $rows[] = [['text' => 'Clear cart', 'callback_data' => 'cl']];

        return $rows;
    }

    private function formatCartText(TelegramUser $user): string
    {
        $linesMap = $user->normalizedCartLines();

        if ($linesMap === []) {
            return 'Your cart is empty. Try «'.self::BTN_BROWSE.'».';
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
            return 'Your cart had only unavailable items — it was refreshed. Open '.self::BTN_BROWSE.'.';
        }

        return "Your cart\n\n".implode("\n", $chunks)."\n\nSubtotal: ".$this->fmtMoney($subtotal)
            ."\n\nTap «".self::BTN_PLACE_ORDER.'» here or on your keyboard.';
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
                'text' => 'No orders yet. '.self::BTN_BROWSE.' to shop.',
                'reply_markup' => $this->replyKeyboardMarkup(),
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
            'reply_markup' => $this->replyKeyboardMarkup(),
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
            [['text' => 'View cart · '.self::BTN_PLACE_ORDER, 'callback_data' => 'vc']],
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
                'text' => 'That item is no longer listed.',
                'reply_markup' => $this->replyKeyboardMarkup(),
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
            [['text' => 'View cart · '.self::BTN_PLACE_ORDER, 'callback_data' => 'vc']],
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
            return 'The menu has no active items yet. Please check again later.';
        }

        $per = config('ordering.menu_page_size', 5);
        $pages = max(1, (int) ceil($total / $per));
        $humanPage = min($pages, $page + 1);

        return 'Menu • page '.$humanPage.' / '.$pages;
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

        $rows[] = [['text' => 'View cart · '.self::BTN_PLACE_ORDER, 'callback_data' => 'vc']];

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

    private function safeEditMessage(string $chatId, int $messageId, string $text, array $keyboard): void
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
                'text' => 'Choose an action below:',
                'reply_markup' => $this->replyKeyboardMarkup(),
            ]);
        }
    }

    private function fmtMoney(string $amount): string
    {
        return config('ordering.currency_prefix').number_format((float) $amount, 2, '.', '');
    }

    private function replyKeyboardMarkup(): string
    {
        return json_encode([
            'keyboard' => [
                [['text' => self::BTN_BROWSE]],
                [['text' => self::BTN_CART], ['text' => self::BTN_CLEAR]],
                [['text' => self::BTN_PLACE_ORDER]],
                [['text' => self::BTN_ORDERS], ['text' => self::BTN_HELP]],
            ],
            'resize_keyboard' => true,
        ]);
    }
}
