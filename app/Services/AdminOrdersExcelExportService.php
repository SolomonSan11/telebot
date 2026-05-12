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
     * @return array{path: string, filename: string}
     *
     * @throws InvalidArgumentException
     */
    public function writeXlsxToTempFile(string $period, ?string $from = null, ?string $to = null): array
    {
        ['spreadsheet' => $spreadsheet, 'filename' => $filename] = $this->createWorkbook($period, $from, $to);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('orders_', true).'.xlsx';

        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return ['path' => $path, 'filename' => $filename];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function buildForPeriod(string $period, ?string $from = null, ?string $to = null): StreamedResponse
    {
        ['spreadsheet' => $spreadsheet, 'filename' => $filename] = $this->createWorkbook($period, $from, $to);

        return response()->streamDownload(function () use ($spreadsheet): void {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{spreadsheet: Spreadsheet, filename: string}
     *
     * @throws InvalidArgumentException
     */
    private function createWorkbook(string $period, ?string $from, ?string $to): array
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

        return [
            'spreadsheet' => $spreadsheet,
            'filename' => 'orders_'.$range['label'].'.xlsx',
        ];
    }
}
