<?php

namespace App\Providers\Filament;

use App\Filament\Merchant\Auth\Login;
use App\Http\Middleware\EmergencyMerchantGate;
use App\Support\BrandColors;
use App\Support\Merchant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * لوحة صاحب المتجر على الموقع (/merchant) — للي عنده آيفون أو يبي يخدم من الكمبيوتر.
 * كل صفحة فيها مربوطة بمتجره هو بس (App\Support\Merchant).
 */
class MerchantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('merchant')
            ->path('merchant')
            ->login(Login::class)
            ->brandName(fn () => Merchant::store()?->name ?? 'لوحة المتجر')
            // شعار ازانكس + اسم المتجر جنبه
            ->brandLogo(fn () => self::logo('azanx-logo.svg'))
            ->darkModeBrandLogo(fn () => self::logo('azanx-logo-white.svg'))
            ->brandLogoHeight('1.8rem')
            ->favicon(fn () => asset('brand/favicon.png'))
            ->colors([
                'primary' => BrandColors::GREEN,
                'warning' => BrandColors::ORANGE,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Merchant/Resources'), for: 'App\Filament\Merchant\Resources')
            ->discoverPages(in: app_path('Filament/Merchant/Pages'), for: 'App\Filament\Merchant\Pages')
            ->discoverWidgets(in: app_path('Filament/Merchant/Widgets'), for: 'App\Filament\Merchant\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // مركز الطوارئ: «قفل تطبيق المتجر» يقفل اللوحة هذي كمان (حتى أزرار Livewire)
            ->middleware([EmergencyMerchantGate::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ])
            // صوت لما يوصل طلب جديد (لو الطلبات مفعّلة في اللوحة)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.brand-font'))
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.merchant-orders-sound'),
            );
    }

    private static function logo(string $file): HtmlString
    {
        $name = e(Merchant::store()?->name ?? 'لوحة المتجر');

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:.6rem;height:100%">'
            .'<img src="'.e(asset('brand/'.$file)).'" alt="ازانكس" style="height:100%;width:auto">'
            .'<span style="font-weight:700;font-size:1rem;white-space:nowrap">'.$name.'</span></span>'
        );
    }
}
