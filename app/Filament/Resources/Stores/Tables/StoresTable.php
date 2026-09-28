<?php

namespace App\Filament\Resources\Stores\Tables;

use App\Filament\Pages\SettlementDesk;
use App\Filament\Support\ShareLink;
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

                // الحالة الفعلية: المفتاح + ساعات العمل + الفتح اليدوي
                TextColumn::make('open_state')
                    ->label('الحالة توّا')
                    ->badge()
                    ->state(fn (Store $record) => $record->statusText())
                    ->color(fn (Store $record) => $record->isAcceptingOrders() ? 'success' : 'gray'),

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
                ShareLink::make(fn (Store $record) => route('link.store', $record))->iconButton()->tooltip('رابط المشاركة'),
                Action::make('toggleOpen')
                    ->label(fn (Store $record) => $record->isAcceptingOrders() ? 'غلق المتجر' : 'افتح توّا')
                    ->icon(fn (Store $record) => $record->isAcceptingOrders() ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (Store $record) => $record->isAcceptingOrders() ? 'danger' : 'success')
                    ->action(function (Store $record) {
                        // خارج ساعات العمل: يفتح يدوياً (يتجاهل الساعات لين وقت الفتح العادي)
                        $record->toggleManual();

                        Notification::make()
                            ->title($record->isAcceptingOrders() ? 'المتجر مفتوح توّا' : 'المتجر مغلق توّا')
                            ->body($record->statusText())
                            ->success()
                            ->send();
                    }),

                Action::make('account')
                    ->label('الحساب')
                    ->icon('heroicon-o-banknotes')
                    ->color('info')
                    ->visible(fn ($record) => $record->user_id && SettlementDesk::canAccess())
                    ->url(fn ($record) => SettlementDesk::getUrl(['party' => 'store', 'account' => $record->user_id])),
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
