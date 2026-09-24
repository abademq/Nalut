<?php

namespace App\Filament\Resources\PaymentGateways\Tables;

use App\Models\PaymentGateway;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PaymentGatewaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')
                    ->label('البوابة')
                    ->weight('bold'),

                TextColumn::make('mode')
                    ->label('الوضع')
                    ->badge()
                    ->formatStateUsing(fn (PaymentGateway $record) => $record->modeLabel())
                    ->color(fn (string $state) => $state === 'live' ? 'success' : 'warning'),

                IconColumn::make('configured')
                    ->label('المفاتيح')
                    ->boolean()
                    ->state(fn (PaymentGateway $record) => $record->isConfigured()),

                ToggleColumn::make('enabled_for_topup')
                    ->label('شحن المحفظة'),

                ToggleColumn::make('enabled_for_order')
                    ->label('دفع الطلبات'),

                TextColumn::make('min_amount')
                    ->label('أقل مبلغ')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل'),

                TextColumn::make('max_amount')
                    ->label('أقصى مبلغ')
                    ->formatStateUsing(fn ($state) => $state
                        ? number_format((float) $state, 2).' د.ل'
                        : 'بلا حد'),
            ])
            ->recordActions([
                EditAction::make()->label('المفاتيح والإعدادات'),
            ]);
    }
}
