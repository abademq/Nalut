<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «عن التطبيق» ووسائل التواصل — تظهر في تطبيق الزبون.
 * تتخزّن في جدول settings بمفاتيح about.*
 */
class AppSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.app-settings';

    /** المفاتيح وقيمها الافتراضية */
    public const DEFAULTS = [
        'about.name'        => 'توصيل نالوت',
        'about.tagline'     => 'اطلب من مطاعم ومتاجر نالوت',
        'about.description' => 'منصة توصيل محلية تربطك بمطاعم ومتاجر نالوت، وتوصّل طلبك لباب بيتك.',
        'about.phone'       => '',
        'about.whatsapp'    => '',
        'about.email'       => '',
        'about.facebook'    => '',
        'about.instagram'   => '',
        'about.website'     => '',
    ];

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'عن التطبيق';
    }

    public function getTitle(): string
    {
        return 'عن التطبيق والتواصل';
    }

    /** كل القيم الحالية — نفسها اللي يرجعها الـ API للتطبيق */
    public static function values(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[substr($key, 6)] = (string) (Setting::get($key) ?? $default);
        }

        return $out;
    }

    public function mount(): void
    {
        $this->form->fill(self::values());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('التعريف')
                    ->description('يظهر في صفحة «عن التطبيق» عند الزبون')
                    ->schema([
                        TextInput::make('name')->label('اسم التطبيق')->required()->maxLength(60),
                        TextInput::make('tagline')->label('شعار قصير')->maxLength(100),
                        Textarea::make('description')->label('الوصف')->rows(5)->maxLength(2000),
                    ]),
                Section::make('التواصل')
                    ->description('اللي تعبّيه بس يظهر للزبون')
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone')->label('رقم الهاتف')->tel()->maxLength(20),
                        TextInput::make('whatsapp')->label('واتساب')->tel()->maxLength(20)
                            ->helperText('بالصيغة الدولية: 218910000000'),
                        TextInput::make('email')->label('البريد')->email()->maxLength(100),
                        TextInput::make('facebook')->label('فيسبوك')->url()->maxLength(255),
                        TextInput::make('instagram')->label('إنستغرام')->url()->maxLength(255),
                        TextInput::make('website')->label('الموقع')->url()->maxLength(255),
                    ]),
            ]);
    }

    public function save(): void
    {
        foreach ($this->form->getState() as $key => $value) {
            if (array_key_exists("about.$key", self::DEFAULTS)) {
                Setting::put("about.$key", (string) ($value ?? ''));
            }
        }

        Notification::make()->title('تم الحفظ')->success()->send();
    }
}
