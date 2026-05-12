<?php

use App\Http\Controllers\Admin\AdminOrdersExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('admin.export')->prefix('admin')->group(function (): void {
    Route::get('orders/export', [AdminOrdersExportController::class, 'download']);
    Route::get('orders/export/ui', [AdminOrdersExportController::class, 'ui']);
});
