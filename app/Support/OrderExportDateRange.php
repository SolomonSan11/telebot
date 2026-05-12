<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class OrderExportDateRange
{
    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, label: string}
     */
    public static function resolve(string $period, ?string $from = null, ?string $to = null): array
    {
        $now = now();

        return match ($period) {
            'day' => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'label' => 'day_'.$now->format('Y-m-d'),
            ],
            'week' => [
                'start' => $now->copy()->startOfWeek(CarbonInterface::MONDAY),
                'end' => $now->copy()->endOfWeek(CarbonInterface::SUNDAY),
                'label' => 'week_'.$now->format('Y').'_W'.$now->isoWeek(),
            ],
            'month' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
                'label' => 'month_'.$now->format('Y-m'),
            ],
            'range' => self::parseRange($from, $to),
            default => throw new InvalidArgumentException('period must be one of: day, week, month, range.'),
        };
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, label: string}
     */
    private static function parseRange(?string $from, ?string $to): array
    {
        if ($from === null || $from === '' || $to === null || $to === '') {
            throw new InvalidArgumentException('For period=range, both from and to are required (Y-m-d).');
        }

        try {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid from or to date. Use Y-m-d.');
        }

        if ($end->lt($start)) {
            throw new InvalidArgumentException('End date must be on or after start date.');
        }

        return [
            'start' => $start,
            'end' => $end,
            'label' => 'range_'.$start->format('Y-m-d').'_to_'.$end->format('Y-m-d'),
        ];
    }
}
