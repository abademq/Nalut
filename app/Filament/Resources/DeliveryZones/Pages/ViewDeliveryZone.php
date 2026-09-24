<?php

namespace App\Filament\Resources\DeliveryZones\Pages;

use App\Filament\Resources\DeliveryZones\DeliveryZoneResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDeliveryZone extends ViewRecord
{
    protected static string $resource = DeliveryZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
