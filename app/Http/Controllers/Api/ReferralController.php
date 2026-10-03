<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «ادعُ صديقك» في تطبيق الزبون */
class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->referrals->summary($request->user())]);
    }

    /** كود صديق: يدوي، أو من الرابط (التطبيق يبعته وحده بعد الدخول) */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $referrer = $this->referrals->apply($request->user(), $data['code']);

        return response()->json([
            'message' => "تم! حسابك مربوط بدعوة {$referrer->name}. الهدية توصلك مع أول طلب مكتمل.",
            'data' => $this->referrals->summary($request->user()->fresh()),
        ]);
    }
}
