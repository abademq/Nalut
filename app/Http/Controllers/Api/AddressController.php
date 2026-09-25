<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Services\GeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // الافتراضي أولاً — التطبيق يختاره تلقائياً في السلة
        return response()->json(['data' => $request->user()->addresses()
            ->orderByDesc('is_default')->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'      => ['nullable', 'string', 'max:30'],
            'details'    => ['required', 'string', 'max:255'],
            'landmark'   => ['nullable', 'string', 'max:255'],
            'lat'        => ['required', 'numeric', 'between:-90,90'],
            'lng'        => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // العنوان برا كل مناطق التوصيل = ما نحفظوهش
        $data['delivery_zone_id'] = GeoService::requireZone($data['lat'], $data['lng'], 'lat')?->id;

        // أول عنوان للزبون يولّي افتراضي تلقائياً
        $isFirst = ! $request->user()->addresses()->exists();

        $address = $request->user()->addresses()->create($data);

        if (($data['is_default'] ?? false) || $isFirst) {
            $this->makeDefault($request, $address);
        }

        return response()->json(['data' => $address], 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'label'    => ['nullable', 'string', 'max:30'],
            'details'  => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'lat'      => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'      => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        // الموقع تغيّر = نعاودو نحددو المنطقة (قبل كانت تضل القديمة)
        if (isset($data['lat'], $data['lng'])) {
            $data['delivery_zone_id'] = GeoService::requireZone($data['lat'], $data['lng'], 'lat')?->id;
        }

        $address->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json(['data' => $address->fresh()]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 403);
        $wasDefault = (bool) $address->is_default;
        $address->delete();

        // مسح الافتراضي = أحدث عنوان باقي ياخذ مكانه
        if ($wasDefault) {
            $request->user()->addresses()->latest()->first()?->update(['is_default' => true]);
        }

        return response()->json(['message' => 'تم الحذف.']);
    }

    /** POST addresses/{address}/default */
    public function setDefault(Request $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 403);
        $this->makeDefault($request, $address);

        return response()->json(['data' => $address->fresh(), 'message' => 'صار العنوان الافتراضي.']);
    }

    private function makeDefault(Request $request, Address $address): void
    {
        $request->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        $address->update(['is_default' => true]);
    }

    /** فحص التغطية قبل الحفظ — التطبيق يسأل وهو يحرّك الدبوس على الخريطة */
    public function coverage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $zone = GeoService::resolveZone((float) $data['lat'], (float) $data['lng']);
        $anyZones = \App\Models\DeliveryZone::where('is_active', true)->exists();
        $covered = $zone !== null || ! $anyZones;

        return response()->json([
            'covered' => $covered,
            'zone'    => $zone?->name,
            'message' => $covered ? null : \App\Support\Texts::get('msg.out_of_coverage'),
        ]);
    }
}
