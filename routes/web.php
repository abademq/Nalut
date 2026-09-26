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


// روابط تفتح التطبيق مباشرة (متجر / صنف / شاشة) — وصفحة بديلة لو التطبيق مش مثبّت
use App\Http\Controllers\Web\AppLinkController;

Route::get('.well-known/assetlinks.json', [AppLinkController::class, 'assetLinks']);
Route::get('s/{store}', [AppLinkController::class, 'store'])->whereNumber('store')->name('link.store');
Route::get('s/{store}/p/{product}', [AppLinkController::class, 'product'])->whereNumber(['store', 'product'])->name('link.product');
Route::get('go/{screen}', [AppLinkController::class, 'screen'])->name('link.screen');
