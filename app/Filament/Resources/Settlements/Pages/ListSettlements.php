<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Pages\SettlementDesk;
use App\Filament\Resources\Settlements\SettlementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('desk')->label('تسوية جديدة')->icon('heroicon-o-plus')
                ->url(SettlementDesk::getUrl()),
        ];
    }
}
