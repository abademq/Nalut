<?php

use App\Http\Controllers\Admin\AlertsController;
use App\Http\Controllers\Admin\DriverMapController;
use App\Http\Controllers\Admin\SettlementPrintController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Web\AppLinkController;
use App\Http\Controllers\Web\MerchantAlertsController;
use App\Http\Controllers\Web\WebOrderController;
use Illuminate\Support\Facades\Route;

// ===== موقع الطلب (للآيفون والكمبيوتر) =====
$webOrder = function () {
    Route::get('manifest.webmanifest', [WebOrderController::class, 'manifest']);
    Route::get('sw.js', [WebOrderController::class, 'serviceWorker']);
    Route::get('{any?}', [WebOrderController::class, 'shell'])->where('any', '^(?!api/|admin|merchant|livewire|storage/|weborder/|admin-api|settlements/|payments/|up$|\.well-known).*$');
};

// على الدومين الفرعي (WEB_ORDER_DOMAIN في .env) — قبل باقي المسارات باش «/» يفتح الموقع
if ($domain = config('weborder.domain')) {
    Route::domain($domain)->name('weborder.domain.')->group($webOrder);
}

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

// لوحة المتجر على الموقع: صوت الطلب الجديد
Route::middleware(['web', 'auth', 'throttle:30,1'])
    ->get('merchant-api/pending', [MerchantAlertsController::class, 'pending']);

// روابط تفتح التطبيق مباشرة (متجر / صنف / شاشة) — وصفحة بديلة لو التطبيق مش مثبّت

Route::get('.well-known/assetlinks.json', [AppLinkController::class, 'assetLinks']);
Route::get('s/{store}', [AppLinkController::class, 'store'])->whereNumber('store')->name('link.store');
Route::get('s/{store}/p/{product}', [AppLinkController::class, 'product'])->whereNumber(['store', 'product'])->name('link.product');
Route::get('go/{screen}', [AppLinkController::class, 'screen'])->name('link.screen');

// واصل التسوية للطباعة (اللوحة، أو رابط موقّع من تطبيق المتجر/السائق)
Route::get('settlements/{settlement}/print', SettlementPrintController::class)
    ->name('settlements.print');

// وعلى المسار /order في أي دومين
Route::prefix(config('weborder.path', 'order'))->name('weborder.')->group($webOrder);
