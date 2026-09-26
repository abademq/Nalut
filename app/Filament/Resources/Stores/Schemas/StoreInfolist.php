<?php

namespace App\Filament\Resources\Stores\Schemas;

use App\Models\Store;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user_id')
                    ->numeric(),
                TextEntry::make('store_type_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('delivery_zone_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('logo')
                    ->placeholder('-'),
                TextEntry::make('cover')
                    ->placeholder('-'),
                TextEntry::make('phone')
                    ->placeholder('-'),
                TextEntry::make('address')
                    ->placeholder('-'),
                TextEntry::make('lat')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('lng')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('commission_percent')
                    ->numeric(),
                TextEntry::make('min_order')
                    ->numeric(),
                TextEntry::make('prep_time_minutes')
                    ->numeric(),
                TextEntry::make('opens_at')
                    ->time(timezone: 'UTC')
                    ->placeholder('-'),
                TextEntry::make('closes_at')
                    ->time(timezone: 'UTC')
                    ->placeholder('-'),
                IconEntry::make('is_open')
                    ->boolean(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('rating_avg')
                    ->numeric(),
                TextEntry::make('rating_count')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('deleted_at')
                    ->dateTime()
                    ->visible(fn (Store $record): bool => $record->trashed()),
            ]);
    }
}
