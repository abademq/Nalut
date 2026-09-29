<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AppContentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerExtrasController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\LegalController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PartyAccountController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StorePanelController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---------- عام ----------
    // appcheck: يتأكد إن الطلب من تطبيقنا (Firebase App Check) — حسب الإعداد
    Route::post('auth/otp', [AuthController::class, 'requestOtp'])->middleware(['throttle:10,1', 'appcheck']);
    Route::post('auth/verify', [AuthController::class, 'verifyOtp'])->middleware(['throttle:10,1', 'appcheck']);
    Route::post('auth/login', [AuthController::class, 'login'])->middleware(['throttle:10,1', 'appcheck']);
    Route::get('app/content', [AppContentController::class, 'show']);
    // الشروط والخصوصية (تنقرا قبل تسجيل الدخول كمان)
    Route::get('legal', [LegalController::class, 'index']);
    Route::get('legal/{key}', [LegalController::class, 'show'])->where('key', '[a-z_\-]+');

    Route::middleware('auth:sanctum')->group(function () {

        // ---------- الموافقة الصريحة والرسائل التسويقية ----------
        Route::get('me/consents', [LegalController::class, 'status']);
        Route::post('me/consents', [LegalController::class, 'accept']);
        Route::post('me/marketing', [LegalController::class, 'marketing']);

        // ---------- تذاكر الدعم (الزبون، السائق، المتجر) ----------
        Route::get('support/categories', [SupportController::class, 'categories']);
        Route::get('support/tickets', [SupportController::class, 'index']);
        Route::post('support/tickets', [SupportController::class, 'store'])->middleware('throttle:5,10');
        Route::get('support/tickets/{ticket}', [SupportController::class, 'show']);
        Route::post('support/tickets/{ticket}/messages', [SupportController::class, 'message'])->middleware('throttle:20,1');
        Route::post('support/tickets/{ticket}/close', [SupportController::class, 'close']);

        // أحداث من داخل التطبيقات لسجل النشاط (دفعات)
        Route::post('activity', [ActivityController::class, 'store'])->middleware('throttle:30,1');

        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'updateProfile']);
        Route::get('me/delete', [AuthController::class, 'deletionCheck']);
        Route::post('me/delete', [AuthController::class, 'deleteAccount'])->middleware('throttle:10,1');
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('wallet', [WalletController::class, 'show']);
        Route::get('wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('wallet/redeem', [WalletController::class, 'redeem']);
        Route::get('payments/gateways', [PaymentController::class, 'gateways']);
        Route::post('payments/otp/send', [PaymentController::class, 'sendOtp'])->middleware('appcheck');
        Route::post('payments/otp/confirm', [PaymentController::class, 'confirmOtp']);
        Route::post('payments/checkout', [PaymentController::class, 'checkout']);
        Route::get('payments/{id}/status', [PaymentController::class, 'status']);

        // ---------- الزبون ----------
        Route::middleware('role:customer')->group(function () {
            Route::get('coverage', [AddressController::class, 'coverage']);
            Route::post('addresses/{address}/default', [AddressController::class, 'setDefault']);
            Route::apiResource('addresses', AddressController::class)->except('show');

            Route::get('store-types', [CatalogController::class, 'types']);
            Route::get('stores', [CatalogController::class, 'stores']);
            Route::get('stores/{store}', [CatalogController::class, 'show']);

            Route::get('orders', [OrderController::class, 'index']);
            Route::post('orders', [OrderController::class, 'store'])->middleware('appcheck');
            Route::post('orders/quote', [OrderController::class, 'quote']);
            Route::get('orders/{order}', [OrderController::class, 'show']);
            Route::get('orders/{order}/track', [OrderController::class, 'track']);
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
            Route::post('orders/{order}/rate', [OrderController::class, 'rate']);

            // المفضلة، إعادة الطلب، السلات الجاهزة، النقاط، والأصناف الناقصة
            Route::get('favorites', [CustomerExtrasController::class, 'favorites']);
            Route::post('favorites/toggle', [CustomerExtrasController::class, 'toggleFavorite']);
            Route::post('orders/{order}/reorder', [CustomerExtrasController::class, 'reorder']);
            Route::post('orders/{order}/substitution', [CustomerExtrasController::class, 'substitution']);
            Route::get('ready-carts/{readyCart}', [CustomerExtrasController::class, 'readyCart']);
            Route::get('saved-carts', [CustomerExtrasController::class, 'savedCarts']);
            Route::post('saved-carts', [CustomerExtrasController::class, 'saveCart']);
            Route::get('saved-carts/{savedCart}', [CustomerExtrasController::class, 'savedCart']);
            Route::delete('saved-carts/{savedCart}', [CustomerExtrasController::class, 'deleteSavedCart']);
            Route::get('points', [CustomerExtrasController::class, 'points']);
            Route::post('points/convert', [CustomerExtrasController::class, 'convertPoints']);
        });

        // ---------- المتجر ----------
        Route::prefix('store')->middleware('role:store')->group(function () {
            Route::get('summary', [StorePanelController::class, 'summary']);
            Route::get('account', [PartyAccountController::class, 'store']);
            Route::post('toggle-open', [StorePanelController::class, 'toggleOpen']);
            Route::get('orders', [StorePanelController::class, 'orders']);
            Route::get('reports/daily', [StorePanelController::class, 'dailyReport']);
            Route::post('orders/{order}/status', [StorePanelController::class, 'updateOrderStatus']);
            Route::post('orders/{order}/unavailable-items', [StorePanelController::class, 'unavailableItems']);
            Route::get('products', [StorePanelController::class, 'products']);
            Route::post('products', [StorePanelController::class, 'storeProduct']);
            Route::post('products/{product}', [StorePanelController::class, 'updateProduct']);
            Route::put('products/{product}/options', [StorePanelController::class, 'syncProductOptions']);
            Route::post('products/{product}/duplicate', [StorePanelController::class, 'duplicateProduct']);
            Route::post('option-images', [StorePanelController::class, 'uploadOptionImage']);
            Route::delete('products/{product}', [StorePanelController::class, 'destroyProduct']);
            Route::get('sections', [StorePanelController::class, 'sections']);
            Route::post('sections', [StorePanelController::class, 'storeSection']);
            Route::post('sections/reorder', [StorePanelController::class, 'reorderSections']);
            Route::post('sections/{id}', [StorePanelController::class, 'updateSection']);
            Route::delete('sections/{id}', [StorePanelController::class, 'destroySection']);

        });

        // ---------- السائق ----------
        Route::prefix('driver')->middleware('role:driver')->group(function () {
            Route::post('online', [DriverController::class, 'setOnline']);
            Route::get('account', [PartyAccountController::class, 'driver']);
            Route::post('location', [DriverController::class, 'updateLocation'])->middleware('throttle:120,1');
            Route::get('available-orders', [DriverController::class, 'available']);
            Route::post('orders/{order}/accept', [DriverController::class, 'accept']);
            Route::post('orders/{order}/status', [DriverController::class, 'updateStatus']);
            Route::get('failure-reasons', [DriverController::class, 'failureReasons']);
            Route::post('orders/{order}/issue', [DriverController::class, 'reportIssue']);
            Route::get('orders', [DriverController::class, 'myOrders']);
            Route::get('zones', [DriverController::class, 'zones']);
            Route::post('zones', [DriverController::class, 'updateZones']);
            Route::get('earnings', [DriverController::class, 'earnings']);
        });
    });
});
