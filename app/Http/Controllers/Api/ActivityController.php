<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * أحداث من داخل التطبيقات (ما تمرّش على السيرفر): فتح متجر، فتح صنف، بحث،
 * إضافة/حذف من السلة، مشاركة، طباعة واصل... التطبيق يجمعها ويبعثها دفعة وحدة.
 */
class ActivityController extends Controller
{
    /** أسماء الأحداث بالعربي — غير المعروف يتسجّل باسمه */
    public const EVENTS = [
        'app.open'         => 'فتح التطبيق',
        'store.view'       => 'فتح متجر',
        'product.view'     => 'فتح صنف',
        'search'           => 'بحث',
        'section.select'   => 'اختيار قسم',
        'cart.add'         => 'إضافة للسلة',
        'cart.remove'      => 'حذف من السلة',
        'cart.quantity'    => 'تغيير كمية في السلة',
        'cart.clear'       => 'تفضية السلة',
        'cart.open'        => 'فتح السلة',
        'checkout.start'   => 'بدأ تأكيد الطلب',
        'share'            => 'مشاركة',
        'order.view'       => 'فتح طلب',
        'receipt.print'    => 'طباعة واصل',
        'call'             => 'اتصال',
        'navigate'         => 'فتح الخريطة للتوجيه',
        'screen'           => 'فتح شاشة',
    ];

    public function store(Request $request): JsonResponse
    {
        if (! Options::get('logs.client_events')) {
            return response()->json(['ok' => true, 'saved' => 0]);
        }

        $data = $request->validate([
            'events'          => ['required', 'array', 'max:50'],
            'events.*.action' => ['required', 'string', 'regex:/^[a-z_.]{2,40}$/'],
            'events.*.label'  => ['nullable', 'string', 'max:160'],
            'events.*.data'   => ['nullable', 'array'],
            'events.*.at'     => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $saved = 0;

        foreach ($data['events'] as $e) {
            $name = self::EVENTS[$e['action']] ?? $e['action'];
            $d = Activity::clean($e['data'] ?? []);

            // الوقت من التطبيق (الأحداث تتجمّع وتنبعث كل شوية) — بحدود معقولة
            $at = isset($e['at']) ? Carbon::createFromTimestampMs($e['at']) : now();
            if ($at->isFuture() || $at->lt(now()->subDay())) {
                $at = now();
            }

            Activity::record(
                action: 'app.'.$e['action'],
                description: $name.(filled($e['label'] ?? null) ? ': '.$e['label'] : ''),
                properties: $d ? ['data' => $d] : [],
                user: $user,
                orderId: isset($d['order_id']) && is_numeric($d['order_id']) ? (int) $d['order_id'] : null,
                storeId: isset($d['store_id']) && is_numeric($d['store_id']) ? (int) $d['store_id'] : null,
                at: $at,
            );
            $saved++;
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }
}
