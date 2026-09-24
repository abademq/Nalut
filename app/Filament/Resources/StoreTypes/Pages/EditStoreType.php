<?php

namespace App\Filament\Resources\StoreTypes\Pages;

use App\Filament\Resources\StoreTypes\StoreTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStoreType extends EditRecord
{
    protected static string $resource = StoreTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
