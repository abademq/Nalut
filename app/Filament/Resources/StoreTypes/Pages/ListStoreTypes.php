<?php

namespace App\Filament\Resources\StoreTypes\Pages;

use App\Filament\Resources\StoreTypes\StoreTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStoreTypes extends ListRecords
{
    protected static string $resource = StoreTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
