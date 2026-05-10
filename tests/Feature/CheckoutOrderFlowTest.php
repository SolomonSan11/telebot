<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TelegramUser;
use App\Services\CheckoutOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CheckoutOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_creates_order_and_reduces_stock(): void
    {
        $p = Product::query()->create([
            'name' => 'Test item',
            'description' => null,
            'price' => '10.00',
            'stock' => 5,
            'active' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_id' => 90001,
            'name' => 'Tester',
            'username' => 'tester',
            'shopping_cart' => [(string) $p->id => 2],
        ]);

        $order = app(CheckoutOrderService::class)->checkout($user);

        $this->assertSame('20.00', (string) $order->total);
        $this->assertCount(1, $order->items);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $p->id,
            'qty' => 2,
        ]);

        $p->refresh();
        $this->assertSame(3, $p->stock);

        $user->refresh();
        $this->assertSame([], $user->normalizedCartLines());
    }

    public function test_checkout_rejects_empty_cart(): void
    {
        $user = TelegramUser::query()->create([
            'telegram_id' => 90002,
            'name' => 'Empty',
            'username' => null,
            'shopping_cart' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(CheckoutOrderService::class)->checkout($user);
    }

    public function test_cart_purge_drops_inactive_products(): void
    {
        $active = Product::query()->create([
            'name' => 'On',
            'price' => '1.00',
            'stock' => 3,
            'active' => true,
        ]);
        $inactive = Product::query()->create([
            'name' => 'Off',
            'price' => '2.00',
            'stock' => 3,
            'active' => false,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_id' => 90003,
            'name' => 'U',
            'username' => null,
            'shopping_cart' => [(string) $active->id => 1, (string) $inactive->id => 2],
        ]);

        $user->purgeStaleCartProducts();

        $user->refresh();
        $this->assertSame([$active->id => 1], $user->normalizedCartLines());
    }
}
