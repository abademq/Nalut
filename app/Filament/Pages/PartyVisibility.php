<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Options;
use App\Support\Perm;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** شن يشوف تطبيق المتجر وتطبيق السائق من معلومات الطلب والفلوس */
class PartyVisibility extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'التسويات';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Perm::can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'ما يظهر للمتجر والسائق';
    }

    public function getTitle(): string
    {
        return 'ما يظهر للمتجر والسائق';
    }

    private static function keys(string $party): array
    {
        return array_filter(array_keys(Options::definitions()), fn ($k) => str_starts_with($k, "show.$party."));
    }

    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $fill = [];
        foreach ([...self::keys('store'), ...self::keys('driver')] as $k) {
            $fill[self::field($k)] = (bool) Options::get($k);
        }
        $this->form->fill($fill);
    }

    public function form(Schema $schema): Schema
    {
        $defs = Options::definitions();
        $toggles = fn (string $party) => array_map(
            fn ($k) => Toggle::make(self::field($k))->label($defs[$k]['label'])->helperText($defs[$k]['help'] ?? null),
            array_values(self::keys($party))
        );

        return $schema->statePath('data')->components([
            Section::make('تطبيق المتجر')
                ->description('اللي يطلع لصاحب المتجر في بطاقة الطلب والسجل والواصل وصفحة «حسابي».')
                ->columns(2)->schema($toggles('store')),
            Section::make('تطبيق السائق')
                ->description('المبلغ اللي يحصّله نقداً من الزبون يطلع للسائق دائماً — لازم يعرفه.')
                ->columns(2)->schema($toggles('driver')),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        foreach ([...self::keys('store'), ...self::keys('driver')] as $k) {
            Setting::put("opt.$k", ! empty($state[self::field($k)]) ? '1' : '0');
        }
        Notification::make()->title('تم الحفظ — التطبيقات تتحدث مع أول تحديث للطلبات')->success()->send();
    }
}
