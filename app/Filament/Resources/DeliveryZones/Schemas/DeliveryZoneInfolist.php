<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DeliveryZoneInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('base_fee')
                    ->numeric(),
                TextEntry::make('fee_per_km')
                    ->numeric(),
                TextEntry::make('min_order')
                    ->numeric(),
                TextEntry::make('center_lat')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('center_lng')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('radius_km')
                    ->numeric(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
