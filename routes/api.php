<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\StorePanelController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;

Route::prefix('v1')->group(function () {

    // ---------- عام ----------
    Route::post('auth/otp', [AuthController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('auth/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'updateProfile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('wallet', [WalletController::class, 'show']);
        Route::get('wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('wallet/redeem', [WalletController::class, 'redeem']);
        Route::get('payments/gateways', [PaymentController::class, 'gateways']);
        Route::post('payments/otp/send', [PaymentController::class, 'sendOtp']);
        Route::post('payments/otp/confirm', [PaymentController::class, 'confirmOtp']);
        Route::post('payments/checkout', [PaymentController::class, 'checkout']);
        Route::get('payments/{id}/status', [PaymentController::class, 'status']);



        // ---------- الزبون ----------
        Route::middleware('role:customer')->group(function () {
            Route::apiResource('addresses', AddressController::class)->except('show');

            Route::get('store-types', [CatalogController::class, 'types']);
            Route::get('stores', [CatalogController::class, 'stores']);
            Route::get('stores/{store}', [CatalogController::class, 'show']);

            Route::get('orders', [OrderController::class, 'index']);
            Route::post('orders', [OrderController::class, 'store']);
            Route::post('orders/quote', [OrderController::class, 'quote']);
            Route::get('orders/{order}', [OrderController::class, 'show']);
            Route::get('orders/{order}/track', [OrderController::class, 'track']);
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
            Route::post('orders/{order}/rate', [OrderController::class, 'rate']);
        });

        // ---------- المتجر ----------
        Route::prefix('store')->middleware('role:store')->group(function () {
            Route::get('summary', [StorePanelController::class, 'summary']);
            Route::post('toggle-open', [StorePanelController::class, 'toggleOpen']);
            Route::get('orders', [StorePanelController::class, 'orders']);
            Route::post('orders/{order}/status', [StorePanelController::class, 'updateOrderStatus']);
            Route::get('products', [StorePanelController::class, 'products']);
            Route::post('products', [StorePanelController::class, 'storeProduct']);
            Route::post('products/{product}', [StorePanelController::class, 'updateProduct']);
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
            Route::post('location', [DriverController::class, 'updateLocation'])->middleware('throttle:120,1');
            Route::get('available-orders', [DriverController::class, 'available']);
            Route::post('orders/{order}/accept', [DriverController::class, 'accept']);
            Route::post('orders/{order}/status', [DriverController::class, 'updateStatus']);
            Route::get('orders', [DriverController::class, 'myOrders']);
            Route::get('zones', [DriverController::class, 'zones']);
            Route::post('zones', [DriverController::class, 'updateZones']);
            Route::get('earnings', [DriverController::class, 'earnings']);
        });
    });
});
