<?php

namespace App\Filament\Resources\Engagement;

use App\Models\AppSection;
use App\Models\StoreType;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** أقسام تطبيق الزبون: مطاعم، متاجر إلكترونية، متاجر... الزبون يختار القسم أول */
class AppSectionResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'settings.manage';

    public const PERM_MANAGE = 'settings.manage';

    protected static ?string $model = AppSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'الكتالوج';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'app-sections';

    public static function getModelLabel(): string
    {
        return 'قسم';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أقسام التطبيق';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم القسم')->required()->maxLength(40)->placeholder('مطاعم'),
            TextInput::make('subtitle')->label('وصف قصير')->maxLength(80)->placeholder('مطاعم ومقاهي وحلويات'),
            TextInput::make('emoji')->label('رمز')->maxLength(8)->placeholder('🍔'),
            ColorPicker::make('color')->label('اللون')->default('#D84315'),
            FileUpload::make('image')->label('صورة (اختياري)')->image()->disk('public')->directory('sections')->maxSize(1024),
            TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
            Toggle::make('is_active')->label('مفعّل')->default(true),
            Placeholder::make('types_hint')->label('')
                ->content('الأنواع اللي تحت القسم تتحدد من «أنواع المتاجر» (خانة «القسم في التطبيق»).')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('القسم')->weight('bold')
                    ->prefix(fn (AppSection $r) => $r->emoji ? $r->emoji.' ' : ''),
                TextColumn::make('types.name')->label('الأنواع')->badge()->placeholder('لا شي بعد'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل'), DeleteAction::make()->label('حذف')]);
    }

    public static function getPages(): array
    {
        return ['index' => AppSectionResource\ManageSections::route('/')];
    }
}
