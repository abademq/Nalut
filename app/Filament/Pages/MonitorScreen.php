<?php

namespace App\Filament\Pages;

use App\Http\Controllers\Admin\MonitorController;
use App\Support\Activity;
use App\Support\Perm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** شاشة المراقبة الحية — تتعرض جوّا اللوحة، وتنفتح على شاشة كبيرة برابط خاص */
class MonitorScreen extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTv;

    protected static string|UnitEnum|null $navigationGroup = 'الإحصاءات';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.monitor-screen';

    public static function canAccess(): bool
    {
        return Perm::can('orders.view');
    }

    public static function getNavigationLabel(): string
    {
        return 'شاشة المراقبة';
    }

    public function getTitle(): string
    {
        return 'شاشة المراقبة';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('افتح على شاشة كاملة')
                ->icon('heroicon-o-arrows-pointing-out')
                ->url(url('monitor'), shouldOpenInNewTab: true),
            Action::make('rotate')
                ->label('رابط شاشة جديد')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->visible(fn () => Perm::can('settings.manage'))
                ->requiresConfirmation()
                ->modalDescription('الرابط القديم يوقف فوراً، ولازم تفتح الجديد على الشاشة الكبيرة. استعمله لو الرابط تسرّب.')
                ->action(function () {
                    MonitorController::rotateKey();
                    Activity::record('monitor.key', 'تبديل رابط شاشة المراقبة');
                    Notification::make()->title('تم — انسخ الرابط الجديد')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'tvUrl' => Perm::can('settings.manage') ? url('monitor').'?key='.MonitorController::key() : null,
        ];
    }
}
