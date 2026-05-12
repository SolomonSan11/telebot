<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminOrdersExportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function export_returns_503_when_token_not_configured(): void
    {
        config(['admin.export_token' => '']);

        $this->get('/admin/orders/export?token=anything')
            ->assertStatus(503);
    }

    #[Test]
    public function export_returns_403_without_token(): void
    {
        config(['admin.export_token' => 'secret-for-tests']);

        $this->get('/admin/orders/export?period=day')
            ->assertForbidden();
    }

    #[Test]
    public function export_returns_403_with_wrong_token(): void
    {
        config(['admin.export_token' => 'secret-for-tests']);

        $this->get('/admin/orders/export?token=wrong&period=day')
            ->assertForbidden();
    }

    #[Test]
    public function export_returns_422_for_invalid_period(): void
    {
        config(['admin.export_token' => 'secret-for-tests']);

        $this->get('/admin/orders/export?token=secret-for-tests&period=year')
            ->assertStatus(422);
    }

    #[Test]
    public function export_downloads_xlsx_with_orders(): void
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->markTestSkipped('Run composer update to install phpoffice/phpspreadsheet.');
        }

        config(['admin.export_token' => 'secret-for-tests']);

        $u = TelegramUser::query()->create([
            'telegram_id' => 111,
            'name' => 'Buyer',
            'username' => 'buyer',
            'shopping_cart' => [],
        ]);

        Order::query()->create([
            'telegram_user_id' => $u->id,
            'total' => '12.34',
            'status' => 'pending',
        ]);

        $response = $this->get('/admin/orders/export?token=secret-for-tests&period=month');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $response->getContent());

        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $this->assertSame('Order ID', $sheet->getCell('A1')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('A2')->getValue());

        unlink($tmp);
    }

    #[Test]
    public function export_accepts_token_via_header(): void
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->markTestSkipped('Run composer update to install phpoffice/phpspreadsheet.');
        }

        config(['admin.export_token' => 'header-secret']);

        $this->get('/admin/orders/export?period=day', [
            'X-Admin-Export-Token' => 'header-secret',
        ])->assertOk();
    }
}
