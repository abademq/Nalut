<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * الشعار وشكل واصلات الطباعة (نسخة المتجر ونسخة السائق).
 * أيقونة التطبيق نفسها (اللي على شاشة الهاتف) تنحط في كود التطبيق — أندرويد ما يسمحش بتغييرها عن بعد.
 */
class BrandingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    /** نسخة المتجر ونسخة السائق — نفس الخيارات بقيم افتراضية مختلفة */
    private const COPY_DEFAULTS = [
        'store' => [
            'title' => 'نسخة المتجر', 'footer' => 'راجع الأصناف قبل التسليم',
            'show_customer' => true, 'show_phone' => true, 'show_address' => true, 'show_driver' => true,
            'show_prices' => true, 'show_pieces' => true, 'show_notes' => true, 'show_logo' => false,
        ],
        // مفتاحها «customer» من الأول — لكنها نسخة السائق: يعرف بيها الطلبية وتفاصيلها
        'customer' => [
            'title' => 'نسخة السائق', 'footer' => 'راجع الأصناف مع الزبون عند التسليم',
            'show_customer' => true, 'show_phone' => true, 'show_address' => true, 'show_driver' => true,
            'show_prices' => true, 'show_pieces' => true, 'show_notes' => true, 'show_logo' => true,
        ],
    ];

    private const GLOBAL_DEFAULTS = [
        'brand.logo'         => '',
        'receipt.header'     => 'توصيل نالوت',
        'receipt.font_scale' => '1',
        'receipt.auto_print' => 'both',
    ];

    public static function canAccess(): bool
    {
        return \App\Support\Perm::can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'الشعار والطباعة';
    }

    public function getTitle(): string
    {
        return 'الشعار وشكل الواصلات';
    }

    /** للـ API: رابط الشعار */
    public static function logoUrl(): ?string
    {
        $path = (string) Setting::get('brand.logo', '');

        return $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    /** للـ API: إعدادات الطباعة كما يستعملها تطبيق المتجر */
    public static function receipt(): array
    {
        $copies = [];
        foreach (self::COPY_DEFAULTS as $copy => $defaults) {
            foreach ($defaults as $key => $default) {
                $value = Setting::get("receipt.$copy.$key", is_bool($default) ? ($default ? '1' : '0') : $default);
                $copies[$copy][$key] = is_bool($default) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (string) $value;
            }
        }

        return [
            'header'     => (string) Setting::get('receipt.header', self::GLOBAL_DEFAULTS['receipt.header']),
            'font_scale' => (float) Setting::get('receipt.font_scale', self::GLOBAL_DEFAULTS['receipt.font_scale']),
            'auto_print' => (string) Setting::get('receipt.auto_print', self::GLOBAL_DEFAULTS['receipt.auto_print']),
            'logo_url'   => self::logoUrl(),
        ] + $copies;
    }

    public function mount(): void
    {
        $r = self::receipt();

        $this->form->fill([
            'logo'       => ((string) Setting::get('brand.logo', '')) ?: null,
            'header'     => $r['header'],
            'font_scale' => (string) $r['font_scale'],
            'auto_print' => $r['auto_print'],
            'store'      => $r['store'],
            'customer'   => $r['customer'],
        ]);
    }

    private static function copyFields(string $copy): array
    {
        return [
            TextInput::make("$copy.title")->label('عنوان النسخة')->required()->maxLength(40),
            TextInput::make("$copy.footer")->label('سطر آخر الواصل')->maxLength(120),
            Toggle::make("$copy.show_logo")->label('الشعار فوق'),
            Toggle::make("$copy.show_customer")->label('اسم الزبون'),
            Toggle::make("$copy.show_phone")->label('هاتف الزبون'),
            Toggle::make("$copy.show_address")->label('العنوان'),
            Toggle::make("$copy.show_driver")->label('اسم السائق'),
            Toggle::make("$copy.show_prices")->label('الأسعار والإجمالي'),
            Toggle::make("$copy.show_pieces")->label('إجمالي القطع'),
            Toggle::make("$copy.show_notes")->label('ملاحظات الزبون'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('الشعار')
                ->description('يظهر في تطبيق الزبون (الدخول وعن التطبيق) وفوق الواصلات لو فعّلته. أيقونة التطبيق على الهاتف تتغيّر من كود التطبيق بس.')
                ->schema([
                    FileUpload::make('logo')->label('الشعار')
                        ->image()->disk('public')->directory('branding')->maxSize(1024)
                        ->helperText('PNG بخلفية شفافة أو بيضاء، مربّع تقريباً (512×512). للطباعة يتحوّل أبيض وأسود.'),
                ]),
            Section::make('الواصلات')
                ->columns(3)
                ->schema([
                    TextInput::make('header')->label('السطر تحت اسم المتجر')->maxLength(60),
                    Select::make('font_scale')->label('حجم الخط')->native(false)->required()->options([
                        '0.9' => 'صغير', '1' => 'عادي', '1.15' => 'كبير', '1.3' => 'كبير جداً',
                    ]),
                    Select::make('auto_print')->label('الطباعة التلقائية')->native(false)->required()->options([
                        'both'     => 'المتجر عند القبول + السائق لما يجهز',
                        'store'    => 'نسخة المتجر عند القبول بس',
                        'customer' => 'نسخة السائق لما يجهز بس',
                        'none'     => 'بدون — الطباعة يدوية',
                    ]),
                ]),
            Tabs::make('copies')->tabs([
                Tab::make('نسخة المتجر')->columns(2)->schema(self::copyFields('store')),
                Tab::make('نسخة السائق')->columns(2)->schema(self::copyFields('customer')),
            ]),
        ]);
    }

    public function save(): void
    {
        $s = $this->form->getState();

        Setting::put('brand.logo', (string) ($s['logo'] ?? ''));
        Setting::put('receipt.header', (string) ($s['header'] ?? ''));
        Setting::put('receipt.font_scale', (string) $s['font_scale']);
        Setting::put('receipt.auto_print', (string) $s['auto_print']);

        foreach (self::COPY_DEFAULTS as $copy => $defaults) {
            foreach ($defaults as $key => $default) {
                $value = $s[$copy][$key] ?? $default;
                Setting::put("receipt.$copy.$key", is_bool($default) ? ($value ? '1' : '0') : (string) $value);
            }
        }

        Notification::make()->title('تم الحفظ — الواصلات الجاية تطلع بالشكل الجديد')->success()->send();
    }
}
