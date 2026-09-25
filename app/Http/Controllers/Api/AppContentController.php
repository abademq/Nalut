<?php

namespace App\Http\Controllers\Api;

use App\Filament\Pages\AppSettings;
use App\Filament\Pages\BrandingSettings;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Support\Options;
use App\Support\Texts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * كل اللي يتدار من لوحة التحكم ويحتاجه التطبيق وقت التشغيل:
 * الإعلانات، «عن التطبيق»، الشعار، النصوص المعدّلة، الخيارات، وشكل الواصلات.
 *
 * ?app=customer|store|driver — بدونها يرجع محتوى تطبيق الزبون (النسخ القديمة).
 */
class AppContentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $app = in_array($request->query('app'), ['customer', 'store', 'driver'], true)
            ? $request->query('app')
            : 'customer';

        $out = [
            'about'    => AppSettings::values(),
            'branding' => ['name' => AppSettings::values()['name'], 'logo_url' => BrandingSettings::logoUrl()],
            // التعديلات بس: {النص الأصلي: النص الجديد}
            'texts'    => (object) Texts::overrides($app),
            'options'  => (object) Options::publicValues(),
        ];

        if ($app === 'customer') {
            $out['banners'] = Banner::live()->get()->map->toApp()->values();
        }

        if ($app === 'store') {
            $out['receipt'] = BrandingSettings::receipt();
        }

        return response()->json($out);
    }
}
