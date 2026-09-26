<?php

namespace App\Http\Controllers\Web;

use App\Filament\Pages\AppSettings;
use App\Filament\Pages\BrandingSettings;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * صفحات الروابط المشتركة. لو التطبيق مثبّت، أندرويد يفتحه مباشرة ومش حيوصل هنا.
 * لو مش مثبّت (أو من كمبيوتر): صفحة فيها المتجر/الصنف + «افتح في التطبيق» و«حمّل التطبيق».
 * ومعلومات Open Graph باش واتساب وفيسبوك يعرضو صورة واسم في المعاينة.
 */
class AppLinkController extends Controller
{
    /** ملف التحقق من الروابط — أندرويد يقراه أول ما يتثبّت التطبيق */
    public function assetLinks(): JsonResponse
    {
        $fingerprints = config('applinks.android_sha256');

        return response()->json($fingerprints ? [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target'   => [
                'namespace'                => 'android_app',
                'package_name'             => config('applinks.android_package'),
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]] : []);
    }

    public function store(Store $store): Response
    {
        abort_unless($store->is_active, 404);

        return $this->page(
            title: $store->name,
            subtitle: $store->description ?: 'اطلب من '.$store->name.' وتوصلك لباب بيتك',
            image: $store->cover ?: $store->logo,
            deepPath: "s/{$store->id}",
        );
    }

    public function product(Store $store, Product $product): Response
    {
        abort_unless($store->is_active && $product->store_id === $store->id, 404);

        $price = number_format($product->effectivePrice(), 2).' د.ل';

        return $this->page(
            title: $product->name.' — '.$store->name,
            subtitle: trim(($product->description ? $product->description.' · ' : '').$price),
            image: $product->image ?: ($store->cover ?: $store->logo),
            deepPath: "s/{$store->id}/p/{$product->id}",
        );
    }

    public function screen(string $screen): Response
    {
        abort_unless(array_key_exists($screen, config('applinks.screens')), 404);

        return $this->page(
            title: AppSettings::values()['name'],
            subtitle: config('applinks.screens')[$screen],
            image: null,
            deepPath: "go/{$screen}",
        );
    }

    private function page(string $title, string $subtitle, ?string $image, string $deepPath): Response
    {
        $package = config('applinks.android_package');
        $play = config('applinks.play_store_url') ?: "https://play.google.com/store/apps/details?id={$package}";

        // intent:// يفتح التطبيق لو مثبّت، ولو لا يمشي لـ Google Play
        $intent = 'intent://'.$deepPath.'#Intent;scheme='.config('applinks.scheme')
            .';package='.$package.';S.browser_fallback_url='.rawurlencode($play).';end';

        $imageUrl = $image
            ? (str_starts_with($image, 'http') ? $image : asset('storage/'.$image))
            : BrandingSettings::logoUrl();

        return response()->view('app-link', [
            'title'    => $title,
            'subtitle' => $subtitle,
            'image'    => $imageUrl,
            'intent'   => $intent,
            'play'     => $play,
            'app'      => AppSettings::values()['name'],
            'url'      => url()->current(),
        ]);
    }
}
