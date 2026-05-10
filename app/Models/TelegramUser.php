<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramUser extends Model
{
    protected $fillable = [
        'telegram_id',
        'name',
        'username',
        'shopping_cart',
    ];

    protected function casts(): array
    {
        return [
            'shopping_cart' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Drop inactive or deleted products so checkout never hangs on phantom lines.
     */
    public function purgeStaleCartProducts(): void
    {
        $lines = $this->normalizedCartLines();
        if ($lines === []) {
            return;
        }

        $alive = Product::query()
            ->whereIn('id', array_keys($lines))
            ->active()
            ->pluck('id')
            ->all();

        $aliveSet = array_fill_keys(array_map('intval', $alive), true);
        $filtered = [];

        foreach ($lines as $productId => $qty) {
            if (isset($aliveSet[(int) $productId])) {
                $filtered[(int) $productId] = $qty;
            }
        }

        if ($filtered !== $lines) {
            $this->shopping_cart = $filtered;
            $this->save();
        }
    }

    /**
     * @return array<int, int>
     */
    public function normalizedCartLines(): array
    {
        $cart = $this->shopping_cart ?? [];
        $out = [];
        foreach ($cart as $key => $qty) {
            $q = max(0, (int) $qty);
            if ($q <= 0) {
                continue;
            }
            $out[(int) $key] = $q;
        }

        return $out;
    }

    public function cartQuantity(int $productId): int
    {
        return $this->normalizedCartLines()[$productId] ?? 0;
    }

    public function incrementCart(Product $product, int $delta = 1): void
    {
        if ($delta === 0) {
            return;
        }

        $lines = $this->normalizedCartLines();
        $current = $lines[$product->id] ?? 0;
        $new = max(0, $current + $delta);

        if ($new <= 0) {
            unset($lines[$product->id]);
        } else {
            if ($delta > 0 && $new > $product->stock) {
                throw new \InvalidArgumentException('Cannot add more «'.$product->name.'». Stock: '.$product->stock.'.');
            }
            $lines[$product->id] = $new;
        }

        $this->shopping_cart = $lines;
        $this->save();
    }

    public function removeOne(int $productId): void
    {
        $lines = $this->normalizedCartLines();
        $current = $lines[$productId] ?? 0;
        if ($current <= 0) {
            return;
        }

        unset($lines[$productId]);
        $next = $current - 1;
        if ($next > 0) {
            $lines[$productId] = $next;
        }

        $this->shopping_cart = $lines;
        $this->save();
    }

    public function clearLine(int $productId): void
    {
        $lines = $this->normalizedCartLines();
        unset($lines[$productId]);
        $this->shopping_cart = $lines;
        $this->save();
    }

    public function clearCart(): void
    {
        $this->shopping_cart = [];
        $this->save();
    }
}
