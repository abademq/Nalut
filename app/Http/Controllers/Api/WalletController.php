<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'code' => ['required', 'string', 'max:20'],
        ]);

        $tx = $this->wallets->redeemCard($request->user(), $data['code']);

        return response()->json([
            'message' => 'تم شحن '.number_format((float) $tx->amount, 2).' د.ل',
            'balance' => (float) $tx->balance_after,
        ]);
    }
}
