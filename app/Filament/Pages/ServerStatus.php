<?php

namespace App\Filament\Pages;

use App\Support\AppCheck;
use App\Support\Perm;
use App\Support\Recaptcha;
use App\Support\ServerMetrics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** حالة السيرفر: الضغط الحالي ومن وين جاي */
class ServerStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.server-status';

    /** نافذة الحساب بالدقايق */
    public int $window = 15;

    public bool $live = true;

    public static function canAccess(): bool
    {
        return Perm::can('server.view');
    }

    public static function getNavigationLabel(): string
    {
        return 'حالة السيرفر';
    }

    public function getTitle(): string
    {
        return 'حالة السيرفر';
    }

    public function setWindow(int $m): void
    {
        $this->window = in_array($m, [5, 15, 60, 180], true) ? $m : 15;
    }

    protected function getViewData(): array
    {
        return [
            's' => ServerMetrics::snapshot($this->window),
            'processes' => ServerMetrics::processes(),
            'tables' => ServerMetrics::biggestTables(),
            'errors' => ServerMetrics::recentErrors(),
            'appcheck' => ['mode' => AppCheck::mode(), 'stats' => AppCheck::stats()],
            'recaptcha' => Recaptcha::adminEnabled(),
        ];
    }
}
