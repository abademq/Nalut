<?php

namespace App\Filament\Pages;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Emergency;
use App\Support\Perm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * مركز الطوارئ — للمدير الكامل بس.
 * كل مفتاح يوقف جزء واحد فوراً، والكل يتسجّل في سجل النشاط ويوصل تنبيه لكل الإدارة.
 */
class EmergencyCenter extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.emergency-center';

    public string $message = '';

    /** app => [min_build, android, ios] */
    public array $builds = [];

    public static function canAccess(): bool
    {
        return Perm::isSuper();
    }

    public static function getNavigationLabel(): string
    {
        return 'مركز الطوارئ';
    }

    public static function getNavigationBadge(): ?string
    {
        $n = count(Emergency::active());

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return 'مركز الطوارئ';
    }

    public function mount(): void
    {
        $this->message = (string) Setting::get('emergency.message', '');
        foreach (Emergency::APPS as $app) {
            $u = Emergency::updateUrls($app);
            $this->builds[$app] = ['min' => (string) Emergency::minBuild($app), 'android' => $u['android'], 'ios' => $u['ios']];
        }
    }

    /** زر كل مفتاح (التأكيد في الصفحة بـ wire:confirm) */
    public function toggle(string $switch): void
    {
        abort_unless(self::canAccess() && array_key_exists($switch, Emergency::SWITCHES), 403);

        $on = ! Emergency::on($switch);
        Emergency::set([$switch], $on, auth()->user());

        Notification::make()
            ->title(($on ? 'اتشغّل: ' : 'رجع للعادي: ').Emergency::SWITCHES[$switch][0])
            ->{$on ? 'danger' : 'success'}()
            ->send();
    }

    public function saveMessage(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->validate(['message' => ['nullable', 'string', 'max:300']]);
        Emergency::setMessage($this->message);
        Activity::record('emergency.message', 'تعديل رسالة الطوارئ');
        Notification::make()->title('تم حفظ الرسالة')->success()->send();
    }

    public function saveBuilds(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->validate([
            'builds.*.min' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'builds.*.android' => ['nullable', 'url', 'max:300'],
            'builds.*.ios' => ['nullable', 'url', 'max:300'],
        ]);
        foreach (Emergency::APPS as $app) {
            $b = $this->builds[$app] ?? [];
            Setting::put("emergency.min_build.$app", (string) max(0, (int) ($b['min'] ?? 0)));
            Setting::put("emergency.update_android.$app", trim((string) ($b['android'] ?? '')));
            Setting::put("emergency.update_ios.$app", trim((string) ($b['ios'] ?? '')));
        }
        Activity::record('emergency.min_build', 'تعديل التحديث الإجباري', null,
            ['min' => array_map(fn ($b) => (int) ($b['min'] ?? 0), $this->builds)]);
        Notification::make()->title('تم الحفظ')->body('التطبيقات القديمة تطلب التحديث أول ما تتفتح.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lockAll')
                ->label('قفل كل التطبيقات')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('قفل كل التطبيقات؟')
                ->modalDescription('تطبيقات الزبون والمتجر والسائق والإدارة (الهاتف) وموقع الطلب ولوحة المتجر يتوقفو فوراً، ويطلع للمستخدمين نص «رسالة الطوارئ». لوحة التحكم هذي تبقى شغّالة.')
                ->schema([
                    TextInput::make('confirm')->label('اكتب كلمة «قفل» للتأكيد')->required()
                        ->in(['قفل'])->validationMessages(['in' => 'اكتب «قفل» بالضبط.']),
                ])
                ->action(function () {
                    Emergency::set(['app_customer', 'app_store', 'app_driver', 'app_admin', 'payments', 'wallet', 'orders'], true, auth()->user());
                    Notification::make()->title('اتقفلت كل التطبيقات والدفع والطلبات')->danger()->send();
                }),

            Action::make('unlockAll')
                ->label('رجّع كل شي للعادي')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('كل مفاتيح الطوارئ ترجع «عادي». تأكد إن الثغرة اتصلّحت واتنشر التحديث قبل.')
                ->visible(fn () => Emergency::active() !== [])
                ->action(function () {
                    Emergency::set(array_keys(Emergency::SWITCHES), false, auth()->user());
                    Notification::make()->title('رجع كل شي للعادي')->success()->send();
                }),

            Action::make('logout')
                ->label('إنهاء الجلسات')
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('warning')
                ->modalHeading('إنهاء جلسات الدخول')
                ->modalDescription('اللي تختارهم يطلعو من التطبيق ويلزمهم يدخلو من جديد برمز تحقق. استعمله لو فيه شك إن توكنات الدخول تسرّبت.')
                ->schema([
                    CheckboxList::make('roles')->label('مين؟')->required()->options([
                        'customer' => 'الزبائن',
                        'store' => 'المتاجر',
                        'driver' => 'السائقين',
                        'admin' => 'تطبيق الإدارة (الهاتف)',
                    ]),
                ])
                ->action(function (array $data) {
                    $n = 0;
                    foreach ((array) $data['roles'] as $role) {
                        $n += Emergency::revokeTokens($role, auth()->user());
                    }
                    Notification::make()->title("انمسحت $n جلسة")->warning()->send();
                }),

            Action::make('adminsOff')
                ->label('إيقاف حسابات الإدارة الأخرى')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('كل حسابات الإدارة تتوقف ما عدا حسابك، ويطلعو من اللوحة فوراً. للحالات اللي فيها شك إن حساب إدارة اخترق. ترجّعهم بزر «إرجاع حسابات الإدارة».')
                ->action(function () {
                    $n = Emergency::disableOtherAdmins(auth()->user());
                    Notification::make()->title("اتوقف $n حساب")->danger()->send();
                }),

            Action::make('adminsOn')
                ->label('إرجاع حسابات الإدارة')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => Emergency::disabledAdmins() !== [])
                ->action(function () {
                    $n = Emergency::restoreAdmins(auth()->user());
                    Notification::make()->title("رجع $n حساب")->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'switches' => collect(Emergency::SWITCHES)->map(fn ($v, $k) => [
                'key' => $k, 'name' => $v[0], 'help' => $v[1], 'on' => Emergency::on($k),
            ])->values(),
            'updatedAt' => Setting::get('emergency.updated_at'),
            'updatedBy' => Setting::get('emergency.updated_by'),
            'log' => ActivityLog::query()->where('action', 'like', 'emergency.%')->latest('id')->limit(15)
                ->with('user:id,name')->get(),
            'apps' => ['customer' => 'الزبون', 'store' => 'المتجر', 'driver' => 'السائق', 'admin' => 'الإدارة'],
        ];
    }
}
