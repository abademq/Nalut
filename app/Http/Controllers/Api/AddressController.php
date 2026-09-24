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
        return response()->json(['data' => $request->user()->addresses()->latest()->get()]);
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

        $data['delivery_zone_id'] = GeoService::resolveZone($data['lat'], $data['lng'])?->id;

        $address = $request->user()->addresses()->create($data);

        if ($data['is_default'] ?? false) {
            $this->makeDefault($request, $address);
        }

        return response()->json(['data' => $address], 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 403);

        $address->update($request->validate([
            'label'    => ['nullable', 'string', 'max:30'],
            'details'  => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'lat'      => ['nullable', 'numeric'],
            'lng'      => ['nullable', 'numeric'],
        ]));

        return response()->json(['data' => $address->fresh()]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 403);
        $address->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }

    private function makeDefault(Request $request, Address $address): void
    {
        $request->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        $address->update(['is_default' => true]);
    }
}
