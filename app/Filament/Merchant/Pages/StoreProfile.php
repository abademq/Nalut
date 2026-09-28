<?php

namespace App\Filament\Merchant\Pages;

use App\Support\Merchant;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * بيانات المتجر اللي صاحبه يقدر يعدّلها.
 * الاسم والنوع والموقع والعمولة وأقل طلب — من الإدارة بس.
 */
class StoreProfile extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.app-settings';

    /** الخانات المسموحة — أي شي غيرها ما يتحفظش */
    public const FIELDS = ['is_open', 'description', 'phone', 'address', 'logo', 'cover', 'opens_at', 'closes_at', 'prep_time_minutes'];

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'بيانات المتجر';
    }

    public function getTitle(): string
    {
        return 'بيانات المتجر';
    }

    public function mount(): void
    {
        $store = Merchant::store();
        abort_unless($store, 403);
        $this->form->fill($store->only(self::FIELDS));
    }

    public function form(Schema $schema): Schema
    {
        $store = Merchant::store();

        return $schema
            ->statePath('data')
            ->components([
                Section::make($store?->name ?? 'المتجر')
                    ->description('الاسم والنوع والموقع وأقل قيمة طلب تتعدّل من الإدارة — تواصل معاهم لو تبي تغيّرها.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_open')->label('المتجر مفتوح توّا ويستقبل طلبات')->columnSpanFull(),
                        TimePicker::make('opens_at')->label('يفتح الساعة')->timezone('UTC')->seconds(false),
                        TimePicker::make('closes_at')->label('يسكّر الساعة')->timezone('UTC')->seconds(false),
                        TextInput::make('prep_time_minutes')->label('وقت التحضير المعتاد (دقيقة)')->numeric()->minValue(1)->maxValue(600),
                        TextInput::make('phone')->label('رقم الهاتف')->tel()->maxLength(20),
                        TextInput::make('address')->label('العنوان')->maxLength(255)->columnSpanFull(),
                        Textarea::make('description')->label('الوصف')->rows(3)->maxLength(500)->columnSpanFull(),
                    ]),
                Section::make('الصور')->columns(2)->schema([
                    FileUpload::make('logo')->label('الشعار')->helperText('مربع 512×512 بكسل (1:1)، الشعار في النص.')->image()->disk('public')->directory('stores')->maxSize(3072),
                    FileUpload::make('cover')->label('صورة الغلاف')->helperText('عريضة 1200×500 بكسل تقريباً (2.4:1)، المهم في النص.')->image()->disk('public')->directory('stores')->maxSize(5120),
                ]),
            ]);
    }

    public function save(): void
    {
        $store = Merchant::store();
        abort_unless($store, 403);

        $store->update(array_intersect_key($this->form->getState(), array_flip(self::FIELDS)));

        Notification::make()->title('تم الحفظ')->success()->send();
    }
}
