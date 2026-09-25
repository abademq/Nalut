<?php

namespace App\Filament\Resources\Banners;

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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** إعلانات الصفحة الرئيسية في تطبيق الزبون */
class BannerResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

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
                ->helperText('المقاس المناسب 1200×500 تقريباً')
                ->columnSpanFull(),

            ColorPicker::make('color')->label('لون الخلفية')
                ->helperText('يستعمل لو ما فيش صورة')->default('#D84315'),

            Select::make('store_id')->label('يفتح متجر عند الضغط')
                ->relationship('store', 'name')->searchable()->preload()
                ->helperText('اختياري'),
            TextInput::make('url')->label('أو رابط خارجي')->url()->maxLength(255),

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
                TextColumn::make('store.name')->label('المتجر')->placeholder('—'),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('d/m/Y H:i')->placeholder('دائم'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('حذف')])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBanners::route('/'),
            'create' => CreateBanner::route('/create'),
            'edit'   => EditBanner::route('/{record}/edit'),
        ];
    }
}
