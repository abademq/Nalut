<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Options;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** إعدادات التشغيل: مدد الطلبات، المخزون، رسوم التوصيل... — الحقول تتبنى من Options::definitions() */
class OperationsSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    private const SECTIONS = [
        'orders'   => 'الطلبات',
        'otp'      => 'رموز التحقق',
        'alerts'   => 'التنبيهات الفورية',
        'points'   => 'نقاط الولاء',
        'stock'    => 'المخزون',
        'delivery' => 'التوصيل والعمولة',
        'tracking' => 'التتبّع',
        'wallet'   => 'كروت الشحن',
    ];

    public static function canAccess(): bool
    {
        return \App\Support\Perm::can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات التشغيل';
    }

    public function getTitle(): string
    {
        return 'إعدادات التشغيل';
    }

    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $values = [];
        foreach (Options::definitions() as $key => $def) {
            $value = Options::get($key);
            $values[self::field($key)] = $def['type'] === 'list' ? implode(',', $value) : $value;
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (self::SECTIONS as $prefix => $title) {
            $fields = [];

            foreach (Options::definitions() as $key => $def) {
                if (! str_starts_with($key, "$prefix.")) {
                    continue;
                }

                $name = self::field($key);

                $field = match (true) {
                    $def['type'] === 'bool'   => Toggle::make($name),
                    isset($def['choices'])    => Select::make($name)->options($def['choices'])->native(false)->required(),
                    in_array($def['type'], ['int', 'float'], true) => TextInput::make($name)->numeric()->required()
                        ->minValue($def['min'] ?? null)->maxValue($def['max'] ?? null)
                        ->step($def['type'] === 'float' ? 'any' : 1),
                    $def['type'] === 'lines'  => Textarea::make($name)->rows(5)->required()->columnSpanFull(),
                    $def['type'] === 'list'   => TextInput::make($name)->required()
                        ->regex('/^\s*\d+(\s*[,،]\s*\d+)*\s*$/u')
                        ->validationMessages(['regex' => 'أرقام مفصولة بفاصلة فقط.']),
                    default                   => TextInput::make($name),
                };

                $fields[] = $field->label($def['label'])->helperText($def['help'] ?? null);
            }

            if ($fields) {
                $sections[] = Section::make($title)->columns(2)->schema($fields);
            }
        }

        $sections[] = Section::make('خيارات أخرى في اللوحة')
            ->description('أسباب تعذّر التسليم، مناطق التوصيل، والإشعارات لكل حالة — كل واحدة في صفحتها تحت «الإعدادات». مدة التحضير لكل متجر تتعدّل من صفحة المتجر.')
            ->schema([]);

        return $schema->statePath('data')->components($sections);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (Options::definitions() as $key => $def) {
            $value = $state[self::field($key)] ?? null;

            $value = match ($def['type']) {
                'bool'  => $value ? '1' : '0',
                'list'  => implode(',', Options::parseList((string) $value)),
                default => (string) $value,
            };

            Setting::put("opt.$key", $value);
        }

        Notification::make()->title('تم الحفظ')->success()->send();
    }
}
