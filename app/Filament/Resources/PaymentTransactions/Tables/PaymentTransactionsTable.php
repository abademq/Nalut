<?php

namespace App\Filament\Resources\PaymentTransactions\Tables;

use App\Models\PaymentTransaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * نسخة مبسّطة عن قصد: أعمدة من جدول payment_transactions فقط،
 * بدون أي تحميل علاقات — عزلاً لمصدر الخطأ.
 */
class PaymentTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('H:i — d/m/Y')
                    ->sortable(),

                TextColumn::make('user_id')
                    ->label('الزبون')
                    ->formatStateUsing(function ($state) {
                        $user = \App\Models\User::find($state);

                        return $user ? $user->name.' — '.$user->phone : '—';
                    }),

                TextColumn::make('gateway')
                    ->label('البوابة')
                    ->badge(),

                TextColumn::make('purpose')
                    ->label('الغرض')
                    ->formatStateUsing(fn ($state) => $state === 'order'
                        ? 'دفع طلب'
                        : 'شحن محفظة'),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'  => 'قيد التنفيذ',
                        'paid'     => 'مدفوعة',
                        'failed'   => 'فشلت',
                        'canceled' => 'ألغاها الزبون',
                        default    => (string) $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'paid'     => 'success',
                        'pending'  => 'warning',
                        'canceled' => 'gray',
                        default    => 'danger',
                    }),

                TextColumn::make('invoice_no')
                    ->label('رقم الفاتورة')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('provider_transaction_id')
                    ->label('مرجع بلوتو')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('failure_reason')
                    ->label('سبب الفشل')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending'  => 'قيد التنفيذ',
                        'paid'     => 'مدفوعة',
                        'failed'   => 'فشلت',
                        'canceled' => 'ملغاة',
                    ]),
            ]);
    }
}
