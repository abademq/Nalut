<?php

namespace App\Filament\Resources\Engagement;

use App\Models\Announcement;
use App\Models\Store;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** شريط العروض: سطر ملوّن فوق التطبيق أو فوق متجر */
class AnnouncementResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'settings.manage';

    public const PERM_MANAGE = 'settings.manage';

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3BottomLeft;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'announcements';

    public static function getModelLabel(): string
    {
        return 'شريط عرض';
    }

    public static function getPluralModelLabel(): string
    {
        return 'شريط العروض';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('text')->label('النص')->required()->maxLength(160)
                ->placeholder('🔥 توصيل مجاني فوق 50 د.ل لين آخر الأسبوع')->columnSpanFull(),
            Select::make('store_id')->label('يظهر فوق')->native(false)->searchable()
                ->options(fn () => Store::orderBy('name')->pluck('name', 'id'))
                ->placeholder('التطبيق كامل (الرئيسية)'),
            TextInput::make('link')->label('يفتح على (اختياري)')->maxLength(255)
                ->placeholder('https://api.dar-almaqam.com.ly/s/5')
                ->helperText('رابط مشاركة لمتجر/صنف/شاشة أو أي رابط'),
            ColorPicker::make('bg_color')->label('لون الخلفية')->default('#D84315'),
            ColorPicker::make('text_color')->label('لون النص')->default('#FFFFFF'),
            DateTimePicker::make('starts_at')->label('يبدا من')->seconds(false),
            DateTimePicker::make('ends_at')->label('ينتهي في')->seconds(false),
            TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ColorColumn::make('bg_color')->label(''),
                TextColumn::make('text')->label('النص')->wrap()->weight('bold'),
                TextColumn::make('store.name')->label('فوق')->placeholder('التطبيق كامل'),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('d/m H:i')->placeholder('—'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل'), DeleteAction::make()->label('حذف')]);
    }

    public static function getPages(): array
    {
        return ['index' => AnnouncementResource\ManageAnnouncements::route('/')];
    }
}
