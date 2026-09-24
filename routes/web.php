<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\Api\PaymentController;
Route::get('payments/callback', [PaymentController::class, 'callback'])
    ->name('payments.callback');