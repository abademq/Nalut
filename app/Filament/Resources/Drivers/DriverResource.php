<?php

namespace App\Filament\Resources\Drivers;

use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\Tables\DriversTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DriverResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'users.view';

    public const PERM_MANAGE = 'users.manage';

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'سائق';
    }

    public static function getPluralModelLabel(): string
    {
        return 'السائقين';
    }

    public static function getNavigationLabel(): string
    {
        return 'السائقين';
    }

    /** عدد المتاحين توّا في شارة القائمة */
    public static function getNavigationBadge(): ?string
    {
        $count = User::where('role', 'driver')
            ->whereHas('driverProfile', fn ($q) => $q->where('is_online', true))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', 'driver')
            ->with(['driverProfile.zones', 'wallet']);
    }

    public static function table(Table $table): Table
    {
        return DriversTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
        ];
    }
}
