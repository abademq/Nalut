<?php

namespace App\Filament\Resources\StoreTypes;

use App\Filament\Resources\StoreTypes\Pages\CreateStoreType;
use App\Filament\Resources\StoreTypes\Pages\EditStoreType;
use App\Filament\Resources\StoreTypes\Pages\ListStoreTypes;
use App\Filament\Resources\StoreTypes\Pages\ViewStoreType;
use App\Filament\Resources\StoreTypes\Schemas\StoreTypeForm;
use App\Filament\Resources\StoreTypes\Schemas\StoreTypeInfolist;
use App\Filament\Resources\StoreTypes\Tables\StoreTypesTable;
use App\Models\StoreType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StoreTypeResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'settings.manage';

    public const PERM_MANAGE = 'settings.manage';

    protected static ?string $model = StoreType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return 'نوع متجر';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنواع المتاجر';
    }

    public static function getNavigationLabel(): string
    {
        return 'أنواع المتاجر';
    }

    public static function form(Schema $schema): Schema
    {
        return StoreTypeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StoreTypeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoreTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListStoreTypes::route('/'),
            'create' => CreateStoreType::route('/create'),
            'view'   => ViewStoreType::route('/{record}'),
            'edit'   => EditStoreType::route('/{record}/edit'),
        ];
    }
}
