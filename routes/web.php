<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\Api\PaymentController;
Route::get('payments/callback', [PaymentController::class, 'callback'])
    ->name('payments.callback');

use App\Http\Controllers\Admin\DriverMapController;

Route::middleware(['web', 'auth'])
    ->get('admin-api/drivers-map', [DriverMapController::class, 'locations']);

// تنبيهات اللوحة الفورية (صوت + إشعار المتصفح)
Route::middleware(['web', 'auth'])
    ->get('admin-api/alerts', [\App\Http\Controllers\Admin\AlertsController::class, 'poll']);
