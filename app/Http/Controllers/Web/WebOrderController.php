<?php

namespace App\Http\Controllers\Web;

use App\Filament\Pages\AppSettings;
use App\Filament\Pages\BrandingSettings;
use App\Http\Controllers\Controller;
use App\Support\Options;
use App\Support\Recaptcha;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * موقع الطلب — نفس تطبيق الزبون من المتصفح (للآيفون والكمبيوتر).
 * صفحة وحدة (SPA) تكلّم نفس الـ API بنفس الحسابات.
 */
class WebOrderController extends Controller
{
    /** المسار الأساسي: '' على الدومين الفرعي، أو /order */
    public static function base(Request $request): string
    {
        $domain = config('weborder.domain');

        return $domain && $request->getHost() === $domain ? '' : '/'.trim(config('weborder.path'), '/');
    }

    public function shell(Request $request)
    {
        $about = AppSettings::values();
        $base = self::base($request);

        return response()->view('weborder.app', [
            'base' => $base,
            'name' => $about['name'] ?? config('app.name'),
            'logo' => BrandingSettings::logoUrl(),
            'enabled' => (bool) Options::get('web.enabled'),
            'config' => [
                'base' => $base,
                'api' => '/api/v1',
                'name' => $about['name'] ?? config('app.name'),
                'logo' => BrandingSettings::logoUrl(),
                'recaptcha' => Recaptcha::configured() ? Recaptcha::siteKey() : null,
                'notice' => (string) Options::get('web.notice'),
                'whatsapp' => $about['whatsapp'] ?? '',
                'phone' => $about['phone'] ?? '',
                'v' => self::version(),
            ],
            'v' => self::version(),
        ])->header('Cache-Control', 'no-cache');
    }

    public function manifest(Request $request)
    {
        $base = self::base($request);
        $name = AppSettings::values()['name'] ?? config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'lang' => 'ar',
            'dir' => 'rtl',
            'start_url' => $base.'/',
            'scope' => $base.'/',
            'display' => 'standalone',
            'background_color' => '#F7F7F9',
            'theme_color' => '#D84315',
            'icons' => [
                ['src' => '/weborder/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/weborder/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/weborder/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /** Service Worker لازم يكون تحت نفس المسار باش يغطي الموقع كله */
    public function serviceWorker(): Response
    {
        $js = str_replace('__V__', self::version(), (string) file_get_contents(public_path('weborder/sw.js')));

        return response($js, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** يتغير مع أي تحديث للملفات — باش المتصفح ياخذ النسخة الجديدة */
    public static function version(): string
    {
        $t = 0;
        foreach (['app.js', 'app.css', 'sw.js'] as $f) {
            $t = max($t, (int) @filemtime(public_path("weborder/$f")));
        }

        return (string) $t;
    }
}
