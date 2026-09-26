<?php

namespace App\Filament\Resources\Stores\Tables;

use App\Models\Store;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class StoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('logo')
                    ->label('الشعار')
                    ->circular()
                    ->disk('public')
                    ->defaultImageUrl(asset('favicon.ico')),

                TextColumn::make('name')
                    ->label('اسم المتجر')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('type.name')
                    ->label('النوع')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('address')
                    ->label('العنوان')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('commission_percent')
                    ->label('العمولة')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('min_order')
                    ->label('أقل طلب')
                    ->money('LYD'),

                TextColumn::make('prep_time_minutes')
                    ->label('وقت التحضير')
                    ->suffix(' دقيقة')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('orders_count')
                    ->label('عدد الطلبات')
                    ->counts('orders')
                    ->badge(),

                IconColumn::make('is_open')
                    ->label('مفتوح')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('مفعّل')
                    ->boolean(),

                TextColumn::make('rating_avg')
                    ->label('التقييم')
                    ->formatStateUsing(fn ($state, Store $record) => $record->rating_count > 0
                        ? number_format((float) $state, 1).' ('.$record->rating_count.')'
                        : 'لا يوجد')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('store_type_id')
                    ->label('النوع')
                    ->relationship('type', 'name'),

                SelectFilter::make('delivery_zone_id')
                    ->label('منطقة التوصيل')
                    ->relationship('zone', 'name'),

                TrashedFilter::make()->label('المحذوفة'),
            ])
            ->recordActions([
                \App\Filament\Support\ShareLink::make(fn (Store $record) => route('link.store', $record))->iconButton()->tooltip('رابط المشاركة'),
                Action::make('toggleOpen')
                    ->label(fn (Store $record) => $record->is_open ? 'غلق المتجر' : 'فتح المتجر')
                    ->icon(fn (Store $record) => $record->is_open ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (Store $record) => $record->is_open ? 'danger' : 'success')
                    ->action(function (Store $record) {
                        $record->update(['is_open' => ! $record->is_open]);

                        Notification::make()
                            ->title($record->is_open ? 'المتجر مفتوح توّا' : 'المتجر مغلق توّا')
                            ->success()
                            ->send();
                    }),

                ViewAction::make()->label('عرض'),
                EditAction::make()->label('تعديل'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('حذف'),
                    RestoreBulkAction::make()->label('استرجاع'),
                ]),
            ]);
    }
}
