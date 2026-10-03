<?php

namespace App\Filament\Pages;

use App\Support\Analytics;
use App\Support\Perm;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** التحليلات: نسب الإكمال والإلغاء، الأوقات والسرعة، قيمة الطلب، رجوع الزبائن، الأقسام والمتاجر والسائقين */
class AnalyticsReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'الإحصاءات';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.analytics-report';

    public string $preset = '7';

    public ?string $from = null;

    public ?string $to = null;

    public static function canAccess(): bool
    {
        return Perm::can('orders.view');
    }

    public static function getNavigationLabel(): string
    {
        return 'التحليلات والأداء';
    }

    public function getTitle(): string
    {
        return 'التحليلات والأداء';
    }

    public function setPreset(string $p): void
    {
        $this->preset = in_array($p, ['today', '7', '30', '90', 'month'], true) ? $p : '7';
        $this->from = $this->to = null;
    }

    protected function getViewData(): array
    {
        [$f, $t] = Analytics::range($this->preset, $this->from ?: null, $this->to ?: null);

        return [
            'r' => Analytics::report($f, $t),
            'money' => Perm::can('finance.view'),
        ];
    }
}
