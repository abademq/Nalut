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
            // صوت الإشعارات من «أصوات الإشعارات»: النغمة (داخل التطبيق) ورابط الصوت الخاص
            'sound'    => \App\Support\Sounds::forApp($app),
        ];

        if ($app === 'customer') {
            $out['banners'] = Banner::live()->get()->map->toApp()->values();
            // أقسام التطبيق (مطاعم، متاجر...) وشريط العروض العام
            $out['sections'] = \App\Models\AppSection::where('is_active', true)->with('types')->orderBy('sort')->get()
                ->map->toApp()->values();
            $out['announcements'] = \App\Models\Announcement::live()->whereNull('store_id')->get()->map->toApp()->values();
        }

        if ($app === 'store') {
            $out['receipt'] = BrandingSettings::receipt();
        }

        return response()->json($out);
    }
}
