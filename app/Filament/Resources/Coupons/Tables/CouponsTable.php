<?php

namespace App\Filament\Resources\Coupons\Tables;

use App\Models\Coupon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->label('الكود')->searchable()->copyable()->weight('bold'),

                TextColumn::make('type')
                    ->label('نوع العرض')
                    ->badge()
                    ->color(fn (Coupon $record) => $record->type === 'free_delivery' ? 'success' : 'info')
                    ->formatStateUsing(fn ($state, Coupon $record) => $record->typeLabel()),

                TextColumn::make('value')
                    ->label('القيمة')
                    ->formatStateUsing(fn ($state, Coupon $record) => match ($record->type) {
                        'free_delivery' => 'رسوم التوصيل',
                        'percent'       => $state.'%',
                        default         => number_format((float) $state, 2).' د.ل',
                    }),

                TextColumn::make('store.name')->label('خاص بمتجر')->placeholder('كل المتاجر'),
                TextColumn::make('min_order')->label('أقل طلب')->money('LYD'),
                TextColumn::make('used_count')->label('مرات الاستخدام')->badge(),
                TextColumn::make('ends_at')->label('ينتهي في')->date('d/m/Y')->placeholder('بدون انتهاء'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                EditAction::make()->label('تعديل'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()->label('حذف')]),
            ]);
    }
}
