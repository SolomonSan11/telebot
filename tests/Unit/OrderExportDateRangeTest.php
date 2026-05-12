<?php

namespace Tests\Unit;

use App\Support\OrderExportDateRange;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderExportDateRangeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function day_is_start_and_end_of_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11 15:30:00', 'UTC'));

        $r = OrderExportDateRange::resolve('day');

        $this->assertSame('2026-05-11 00:00:00', $r['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-11 23:59:59', $r['end']->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('day_', $r['label']);
    }

    #[Test]
    public function week_is_monday_through_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11 12:00:00', 'UTC')); // Monday

        $r = OrderExportDateRange::resolve('week');

        $this->assertSame('2026-05-11', $r['start']->toDateString());
        $this->assertSame('2026-05-17', $r['end']->toDateString());
    }

    #[Test]
    public function month_bounds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11 12:00:00', 'UTC'));

        $r = OrderExportDateRange::resolve('month');

        $this->assertSame('2026-05-01 00:00:00', $r['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-31 23:59:59', $r['end']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function range_requires_from_and_to(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrderExportDateRange::resolve('range', '2026-05-01', null);
    }

    #[Test]
    public function range_inclusive(): void
    {
        $r = OrderExportDateRange::resolve('range', '2026-05-01', '2026-05-03');

        $this->assertSame('2026-05-01 00:00:00', $r['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-03 23:59:59', $r['end']->format('Y-m-d H:i:s'));
    }
}
