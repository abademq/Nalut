<?php

namespace App\Filament\Resources\RechargeCards;

use App\Filament\Resources\RechargeCards\Pages\ListRechargeCards;
use App\Filament\Resources\RechargeCards\Tables\RechargeCardsTable;
use App\Models\RechargeCard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RechargeCardResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'cards.manage';

    public const PERM_MANAGE = 'cards.manage';

    protected static ?string $model = RechargeCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    /** الكروت تتولّد بدفعات من زر «توليد كروت» */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'كرت شحن';
    }

    public static function getPluralModelLabel(): string
    {
        return 'كروت الشحن';
    }

    public static function getNavigationLabel(): string
    {
        return 'كروت الشحن';
    }

    public static function getNavigationBadge(): ?string
    {
        $unused = RechargeCard::where('status', 'unused')->count();

        return $unused > 0 ? (string) $unused : null;
    }

    public static function table(Table $table): Table
    {
        return RechargeCardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRechargeCards::route('/'),
        ];
    }
}
