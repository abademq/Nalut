<?php

namespace App\Filament\Resources\Banners;

use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Models\Banner;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** إعلانات تطبيق الزبون: الرئيسية، الأقسام، صفحة متجر، السلة */
class BannerResource extends Resource
{
    use GuardedByPermission;

    public const PERM_VIEW = 'settings.manage';

    public const PERM_MANAGE = 'settings.manage';

    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'إعلان';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الإعلانات';
    }

    public static function getNavigationLabel(): string
    {
        return 'الإعلانات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('العنوان')->maxLength(60)
                ->helperText('يظهر فوق الإعلان — اتركه فاضي لو الصورة فيها الكلام'),
            TextInput::make('subtitle')->label('نص صغير')->maxLength(100),

            FileUpload::make('image')->label('الصورة')
                ->image()->disk('public')->directory('banners')->maxSize(3072)
                ->helperText('عريضة 1200×500 بكسل تقريباً (2.4:1)، والكلام المهم في النص — الأطراف ممكن تنقص في بعض الشاشات. لحد 3 ميغا.')
                ->columnSpanFull(),

            ColorPicker::make('color')->label('لون الخلفية')
                ->helperText('يستعمل لو ما فيش صورة')->default('#D84315'),

            Select::make('store_id')->label('يفتح متجر عند الضغط')
                ->relationship('store', 'name')->searchable()->preload()
                ->helperText('اختياري'),
            TextInput::make('url')->label('أو رابط خارجي')->url()->maxLength(255),

            Select::make('placement')->label('وين يطلع الإعلان')
                ->options(Banner::PLACEMENTS)->default('home')->required()->native(false)->live()
                ->helperText('الرئيسية = قبل ما الزبون يختار قسم. لو اختار قسم تطلع إعلانات القسم (ولو ما فيش، إعلانات الرئيسية حسب الإعدادات)'),
            Select::make('app_section_id')->label('القسم')
                ->relationship('section', 'name')->preload()->native(false)
                ->visible(fn ($get) => $get('placement') === 'section')
                ->required(fn ($get) => $get('placement') === 'section'),
            Select::make('show_store_id')->label('يطلع في صفحة المتجر')
                ->relationship('showStore', 'name')->searchable()->preload()
                ->visible(fn ($get) => $get('placement') === 'store')
                ->required(fn ($get) => $get('placement') === 'store'),

            TextInput::make('sort')->label('الترتيب')->numeric()->default(0)
                ->helperText('الأصغر يظهر أول'),

            DateTimePicker::make('starts_at')->label('يبدا من')->seconds(false),
            DateTimePicker::make('ends_at')->label('ينتهي في')->seconds(false)
                ->helperText('اتركهم فاضيين = يظهر دائماً'),

            Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                ImageColumn::make('image')->label('الصورة')->disk('public'),
                TextColumn::make('title')->label('العنوان')->placeholder('—'),
                TextColumn::make('placement')->label('وين يطلع')->badge()
                    ->state(fn (Banner $record) => $record->placementLabel()),
                TextColumn::make('store.name')->label('يفتح متجر')->placeholder('—'),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('d/m/Y H:i')->placeholder('دائم'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->filters([
                SelectFilter::make('placement')->label('وين يطلع')->options(Banner::PLACEMENTS),
                SelectFilter::make('app_section_id')->label('القسم')->relationship('section', 'name'),
            ])
            ->recordActions([EditAction::make()->label('تعديل')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('حذف')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanners::route('/'),
            'create' => CreateBanner::route('/create'),
            'edit' => EditBanner::route('/{record}/edit'),
        ];
    }
}
