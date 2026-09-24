<?php

namespace App\Filament\Resources\NotificationSettings\Tables;

use App\Models\NotificationSetting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->defaultSort('id')
            ->columns([
                TextColumn::make('role')
                    ->label('المستلم')
                    ->badge()
                    ->formatStateUsing(fn (NotificationSetting $record) => $record->roleLabel())
                    ->color(fn (string $state) => match ($state) {
                        'customer' => 'gray',
                        'store'    => 'warning',
                        'driver'   => 'info',
                        default    => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('الحالة / الحدث')
                    ->formatStateUsing(fn ($state) => NotificationSetting::statusLabel($state))
                    ->weight('bold'),

                ToggleColumn::make('enabled')
                    ->label('يرسل إشعار')
                    ->onColor('success')
                    ->offColor('gray'),

                TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('المستلم')
                    ->options(NotificationSetting::ROLES),
            ]);
    }
}
