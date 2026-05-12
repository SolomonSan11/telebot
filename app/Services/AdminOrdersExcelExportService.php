<?php

namespace App\Services;

use App\Models\Order;
use App\Support\OrderExportDateRange;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOrdersExcelExportService
{
    /**
     * @throws InvalidArgumentException
     */
    public function buildForPeriod(string $period, ?string $from = null, ?string $to = null): StreamedResponse
    {
        $range = OrderExportDateRange::resolve($period, $from, $to);

        $orders = Order::query()
            ->with(['telegramUser', 'items.product'])
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->orderBy('id')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Orders');

        $headers = [
            'Order ID',
            'Created at',
            'Status',
            'Customer Telegram ID',
            'Customer username',
            'Customer name',
            'Total',
            'Line items',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $rows = [];
        foreach ($orders as $order) {
            $u = $order->telegramUser;
            $lines = [];
            foreach ($order->items as $item) {
                $p = $item->relationLoaded('product') && $item->product ? $item->product->name : '#'.$item->product_id;
                $lines[] = $p.' × '.$item->qty.' @ '.$item->price;
            }

            $rows[] = [
                $order->id,
                $order->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                $order->status,
                $u?->telegram_id ?? '',
                $u?->username ?? '',
                $u?->name ?? '',
                (float) $order->total,
                implode('; ', $lines),
            ];
        }

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'orders_'.$range['label'].'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
