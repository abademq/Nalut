<?php

namespace App\Filament\Resources\WalletTransactions\Tables;

use App\Models\WalletTransaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['wallet.user', 'order', 'creator']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('H:i — d/m/Y')
                    ->sortable(),

                TextColumn::make('wallet.user.name')
                    ->label('الحساب')
                    ->searchable()
                    ->description(fn (WalletTransaction $record) => $record->wallet?->user?->role?->label()),

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
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('balance_after')
                    ->label('الرصيد بعدها')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل'),

                TextColumn::make('order.code')
                    ->label('الطلب')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('note')
                    ->label('ملاحظة')
                    ->placeholder('—')
                    ->limit(30),

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

                Filter::make('credits')
                    ->label('الداخل فقط')
                    ->query(fn (Builder $q) => $q->where('amount', '>=', 0)),

                Filter::make('debits')
                    ->label('الخارج فقط')
                    ->query(fn (Builder $q) => $q->where('amount', '<', 0)),

                Filter::make('today')
                    ->label('اليوم')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', today())),
            ]);
    }
}
