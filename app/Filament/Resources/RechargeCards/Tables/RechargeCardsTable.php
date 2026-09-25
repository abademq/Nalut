<?php

namespace App\Filament\Resources\RechargeCards\Tables;

use App\Models\RechargeCard;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RechargeCardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('الكود')
                    ->formatStateUsing(fn ($state) => \App\Models\RechargeCard::format((string) $state))
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->fontFamily('mono'),

                TextColumn::make('amount')
                    ->label('القيمة')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (RechargeCard $record) => $record->statusLabel())
                    ->color(fn (RechargeCard $record) => match ($record->status) {
                        'unused'   => 'success',
                        'used'     => 'gray',
                        'disabled' => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('batch')
                    ->label('الدفعة')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('user.name')
                    ->label('استعمله')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('used_at')
                    ->label('تاريخ الاستعمال')
                    ->dateTime('H:i — d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('expires_at')
                    ->label('ينتهي في')
                    ->date('d/m/Y')
                    ->placeholder('بدون انتهاء')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('تاريخ التوليد')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'unused'   => 'غير مستعمل',
                        'used'     => 'مستعمل',
                        'disabled' => 'موقوف',
                    ]),

                SelectFilter::make('batch')
                    ->label('الدفعة')
                    ->options(fn () => RechargeCard::query()
                        ->whereNotNull('batch')
                        ->distinct()
                        ->pluck('batch', 'batch')
                        ->all()),
            ])
            ->recordActions([
                Action::make('disable')
                    ->label('إيقاف')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('الكرت ما يقدرش حد يستعمله بعد الإيقاف.')
                    ->visible(fn (RechargeCard $record) => $record->status === 'unused')
                    ->action(fn (RechargeCard $record) => $record->update(['status' => 'disabled'])),

                Action::make('enable')
                    ->label('تفعيل')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (RechargeCard $record) => $record->status === 'disabled')
                    ->action(fn (RechargeCard $record) => $record->update(['status' => 'unused'])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('حذف'),
                ]),
            ]);
    }
}
