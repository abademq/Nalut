<?php

use App\Http\Controllers\Admin\AlertsController;
use App\Http\Controllers\Admin\DriverMapController;
use App\Http\Controllers\Admin\SettlementPrintController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Web\AppLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('payments/callback', [PaymentController::class, 'callback'])
    ->name('payments.callback');

Route::middleware(['web', 'auth'])
    ->get('admin-api/drivers-map', [DriverMapController::class, 'locations']);

// تنبيهات اللوحة الفورية (صوت + إشعار المتصفح)
Route::middleware(['web', 'auth'])
    ->get('admin-api/alerts', [AlertsController::class, 'poll']);

// روابط تفتح التطبيق مباشرة (متجر / صنف / شاشة) — وصفحة بديلة لو التطبيق مش مثبّت

Route::get('.well-known/assetlinks.json', [AppLinkController::class, 'assetLinks']);
Route::get('s/{store}', [AppLinkController::class, 'store'])->whereNumber('store')->name('link.store');
Route::get('s/{store}/p/{product}', [AppLinkController::class, 'product'])->whereNumber(['store', 'product'])->name('link.product');
Route::get('go/{screen}', [AppLinkController::class, 'screen'])->name('link.screen');

// واصل التسوية للطباعة (اللوحة، أو رابط موقّع من تطبيق المتجر/السائق)
Route::get('settlements/{settlement}/print', SettlementPrintController::class)
    ->name('settlements.print');
