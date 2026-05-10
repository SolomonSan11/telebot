<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CheckoutOrderService
{
    public function checkout(TelegramUser $customer): Order
    {
        $lines = $customer->normalizedCartLines();

        if ($lines === []) {
            throw new InvalidArgumentException('Your cart is empty.');
        }

        return DB::transaction(function () use ($customer, $lines) {
            $products = Product::query()
                ->whereIn('id', array_keys($lines))
                ->where('active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = '0.00';
            $prepared = [];

            foreach ($lines as $productId => $qty) {
                $pid = (int) $productId;
                $q = max(1, (int) $qty);
                $product = $products->get($pid);

                if (! $product) {
                    throw new InvalidArgumentException('A product in your cart is no longer available. Open «My Cart» to refresh.');
                }
                if ($product->stock < $q) {
                    throw new InvalidArgumentException("Not enough stock for «{$product->name}». Available: {$product->stock}.");
                }

                $lineTotal = bcmul((string) $product->price, (string) $q, 2);
                $total = bcadd($total, $lineTotal, 2);
                $prepared[] = [$product, $q];
            }

            if ($prepared === []) {
                throw new InvalidArgumentException('Your cart is empty.');
            }

            $order = Order::query()->create([
                'telegram_user_id' => $customer->id,
                'total' => $total,
                'status' => 'pending',
            ]);

            foreach ($prepared as [$product, $q]) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => $q,
                    'price' => $product->price,
                ]);
                $product->decrement('stock', $q);
            }

            $customer->clearCart();

            return $order->fresh(['items.product', 'telegramUser']);
        });
    }
}
