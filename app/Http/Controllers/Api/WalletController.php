<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->walletFor($request->user());

        return response()->json([
            'balance'  => (float) $wallet->balance,
            'currency' => 'د.ل',
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->wallets->walletFor($request->user());

        $items = WalletTransaction::where('wallet_id', $wallet->id)
            ->latest('id')
            ->paginate(25)
            ->through(fn ($t) => [
                'id'            => $t->id,
                'type'          => $t->type,
                'type_label'    => $t->typeLabel(),
                'amount'        => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'note'          => $t->note,
                'created_at'    => $t->created_at,
            ]);

        return response()->json($items);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:24'],
        ]);

        // حماية من تخمين أرقام الكروت: المحاولات الفاشلة فقط تنحسب
        $userKey = 'card-redeem:user:'.$request->user()->id;
        $ipKey   = 'card-redeem:ip:'.$request->ip();

        foreach ([[$userKey, 'wallet.redeem_max_failures_per_user'], [$ipKey, 'wallet.redeem_max_failures_per_ip']] as [$key, $cfg]) {
            if (RateLimiter::tooManyAttempts($key, (int) config($cfg))) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages([
                    'code' => "محاولات خاطئة كثيرة. حاول بعد {$minutes} دقيقة.",
                ]);
            }
        }

        try {
            $tx = $this->wallets->redeemCard($request->user(), $data['code']);
        } catch (ValidationException $e) {
            RateLimiter::hit($userKey, 3600);
            RateLimiter::hit($ipKey, 3600);
            Log::warning('Failed card redeem', ['user' => $request->user()->id, 'ip' => $request->ip()]);
            throw $e;
        }

        return response()->json([
            'message' => 'تم شحن '.number_format((float) $tx->amount, 2).' د.ل',
            'balance' => (float) $tx->balance_after,
        ]);
    }
}
