<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOrdersExcelExportService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdminOrdersExportController extends Controller
{
    public function download(Request $request, AdminOrdersExcelExportService $exporter)
    {
        $period = strtolower((string) $request->query('period', 'day'));

        try {
            return $exporter->buildForPeriod(
                $period,
                $request->query('from'),
                $request->query('to'),
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function ui(Request $request)
    {
        $token = (string) $request->query('token', '');

        return view('admin.orders_export', [
            'token' => $token,
            'hasToken' => $token !== '',
        ]);
    }
}
