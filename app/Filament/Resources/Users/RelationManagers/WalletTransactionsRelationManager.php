<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\WalletTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** السجل المالي الخاص بمستخدم واحد */
class WalletTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'walletTransactions';

    protected static ?string $title = 'السجل المالي';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->heading('السجل المالي')
            ->description(fn () => 'الرصيد الحالي: '
                .number_format($this->getOwnerRecord()->walletBalance(), 2).' د.ل')
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('H:i — d/m/Y')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('نوع الحركة')
                    ->badge()
                    ->formatStateUsing(fn (WalletTransaction $record) => $record->typeLabel())
                    ->color(fn (WalletTransaction $record) => $record->isCredit() ? 'success' : 'danger'),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->formatStateUsing(fn ($state) => ($state >= 0 ? '+' : '−')
                        .number_format(abs((float) $state), 2).' د.ل')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->weight('bold'),

                TextColumn::make('balance_after')
                    ->label('الرصيد بعدها')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل'),

                TextColumn::make('order.code')
                    ->label('الطلب')
                    ->placeholder('—'),

                TextColumn::make('note')
                    ->label('ملاحظة')
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('creator.name')
                    ->label('نفّذها')
                    ->placeholder('النظام')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('نوع الحركة')
                    ->options(WalletTransaction::TYPES)
                    ->multiple(),
            ]);
    }
}
