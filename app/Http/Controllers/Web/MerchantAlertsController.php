<?php

namespace App\Http\Controllers\Web;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Support\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** لوحة المتجر على الموقع: آخر طلب جديد يستنى القبول — للصوت والإشعار */
class MerchantAlertsController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && Merchant::canUse($user) && Merchant::ordersEnabled(), 403);

        $q = $user->store->orders()
            ->where('status', OrderStatus::Pending->value)
            ->where(fn ($w) => $w->where('payment_method', '!=', 'card')->orWhere('is_paid', true));

        $latest = (clone $q)->latest('id')->first(['id', 'code']);

        return response()->json([
            'count' => $q->count(),
            'latest' => $latest ? ['id' => $latest->id, 'code' => $latest->code] : null,
        ]);
    }
}
