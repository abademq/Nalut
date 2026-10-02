<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Activity;
use App\Support\AppTheme;
use App\Support\Perm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** ألوان التطبيقات الأربعة — تتطبّق على الهواتف أول ما التطبيق يتحدّث (بدون نسخة جديدة من المتجر) */
class AppThemeSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.app-theme';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Perm::can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'مظهر التطبيقات';
    }

    public function getTitle(): string
    {
        return 'مظهر التطبيقات (الألوان)';
    }

    public function mount(): void
    {
        $this->form->fill(AppTheme::values());
    }

    public function form(Schema $schema): Schema
    {
        $fields = [];
        foreach (AppTheme::COLORS as $key => [$default, $label, $help]) {
            $fields[] = ColorPicker::make($key)->label($label)
                ->helperText(trim($help.' الأصلي: '.$default))
                ->regex('/^#[0-9A-Fa-f]{6}$/')
                ->validationMessages(['regex' => 'صيغة #RRGGBB'])
                ->required()
                ->live();
        }

        return $schema->statePath('data')->components([
            Section::make('ألوان التطبيقات الأربعة')
                ->description('الزبون، الكابتن، التاجر، والإدارة. التغيير يوصل للهواتف أول ما المستخدم يفتح التطبيق أو يرجعله — بدون تحديث من المتجر. أيقونة التطبيق على الشاشة الرئيسية ما تتغيّرش من هني.')
                ->columns(3)
                ->schema($fields),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('رجّع ألوان الهوية الأصلية')
                ->color('gray')
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->action(function () {
                    $this->persist(AppTheme::defaults());
                    $this->form->fill(AppTheme::values());
                }),
        ];
    }

    public function save(): void
    {
        $this->persist($this->form->getState());
    }

    private function persist(array $state): void
    {
        foreach (array_keys(AppTheme::COLORS) as $k) {
            Setting::put("theme.$k", strtoupper((string) ($state[$k] ?? AppTheme::COLORS[$k][0])));
        }
        Setting::put('theme.version', (string) ((int) Setting::get('theme.version', 0) + 1));
        Activity::record('settings.theme', 'تعديل ألوان التطبيقات');

        Notification::make()->title('تم الحفظ')->body('الألوان توصل للتطبيقات أول ما تتفتح.')->success()->send();
    }
}
