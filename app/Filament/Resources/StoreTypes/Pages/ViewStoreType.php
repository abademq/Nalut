<?php

namespace App\Filament\Resources\StoreTypes\Pages;

use App\Filament\Resources\StoreTypes\StoreTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStoreType extends ViewRecord
{
    protected static string $resource = StoreTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
