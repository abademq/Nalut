<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Settlement;
use App\Services\SettlementService;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «حسابي» في تطبيق المتجر والسائق: الرصيد، الطلبات اللي ما تسوّتش، والتسويات بواصلاتها */
class PartyAccountController extends Controller
{
    public function store(Request $request, SettlementService $s): JsonResponse
    {
        return $this->show($request, $s, 'store');
    }

    public function driver(Request $request, SettlementService $s): JsonResponse
    {
        return $this->show($request, $s, 'driver');
    }

    private function show(Request $request, SettlementService $service, string $party): JsonResponse
    {
        if (! Options::get("show.$party.balance")) {
            return response()->json(['enabled' => false]);
        }

        $user = $request->user();
        $st = $service->statement($user, $party);
        $sum = $st['sum'];

        $lines = $party === 'store'
            ? [
                ['label' => 'قيمة الأصناف', 'amount' => $sum['subtotal']],
                ['label' => 'عمولة المنصة', 'amount' => -$sum['commission'], 'style' => 'minus'],
                ['label' => 'صافيك من الطلبات', 'amount' => $sum['store_net'], 'style' => 'total'],
            ]
            : [
                ['label' => 'أجرة التوصيل', 'amount' => $sum['driver_earning']],
                ['label' => 'كاش حصّلته من الزبائن', 'amount' => -$sum['cash'], 'style' => 'minus'],
                ['label' => 'الصافي من الطلبات', 'amount' => round($sum['driver_earning'] - $sum['cash'], 2), 'style' => 'total'],
            ];
        foreach ($st['other'] as $t) {
            $lines[] = ['label' => $t->typeLabel().($t->note ? " — {$t->note}" : ''), 'amount' => (float) $t->amount, 'style' => 'muted'];
        }

        $settlements = Settlement::where('user_id', $user->id)->where('party', $party)->latest('id')->limit(30)->get();

        return response()->json([
            'enabled' => true,
            'balance' => $st['balance'],
            // pay = الإدارة تدفعلك · receive = عليك للإدارة
            'direction' => $st['direction'],
            'due' => $st['due'],
            'message' => match ($st['direction']) {
                'pay' => 'لك عند الإدارة '.number_format($st['due'], 2).' د.ل',
                'receive' => 'عليك للإدارة '.number_format($st['due'], 2).' د.ل',
                default => 'حسابك متسوّي',
            },
            'unsettled' => ['orders' => $sum['orders'], 'from' => $st['from'], 'lines' => $lines],
            'last_settlement_at' => $st['last']?->created_at,
            'settlements' => $settlements->map(fn (Settlement $x) => [
                'id' => $x->id,
                'number' => $x->number,
                'at' => $x->created_at,
                'direction' => $x->direction,
                'label' => $x->direction === 'pay' ? 'استلمت من الإدارة' : 'سلّمت للإدارة',
                'amount' => $x->amount,
                'method' => $x->methodLabel(),
                'orders' => $x->orders_count,
                'balance_after' => $x->balance_after,
                'cancelled' => $x->isCancelled(),
                'print_url' => $x->signedPrintUrl('80'),
            ])->values(),
        ]);
    }
}
