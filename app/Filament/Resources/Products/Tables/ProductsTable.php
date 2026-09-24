<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                ImageColumn::make('image')
                    ->label('الصورة')
                    ->disk('public')
                    ->square(),

                TextColumn::make('name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('store.name')
                    ->label('المتجر')
                    ->searchable()
                    ->badge(),

                TextColumn::make('price')
                    ->label('السعر')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->sortable(),

                TextColumn::make('discount_price')
                    ->label('سعر العرض')
                    ->formatStateUsing(fn ($state) => $state
                        ? number_format((float) $state, 2).' د.ل'
                        : '—')
                    ->sortable(),

                TextColumn::make('stock_quantity')
                    ->label('المخزون')
                    ->badge()
                    ->formatStateUsing(fn ($state, Product $record) => $record->track_stock
                        ? (string) $record->stock_quantity
                        : 'متوفر دائماً')
                    ->color(fn (Product $record) => ! $record->track_stock
                        ? 'gray'
                        : ($record->stock_quantity <= 0
                            ? 'danger'
                            : ($record->isLowStock() ? 'warning' : 'success'))),

                TextColumn::make('max_per_order')
                    ->label('أقصى كمية')
                    ->placeholder('بدون حد')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_available')
                    ->label('متوفر')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('store_id')
                    ->label('المتجر')
                    ->relationship('store', 'name')
                    ->searchable(),

                Filter::make('low_stock')
                    ->label('مخزون منخفض')
                    ->query(fn ($query) => $query->where('track_stock', true)
                        ->whereNotNull('low_stock_alert')
                        ->whereColumn('stock_quantity', '<=', 'low_stock_alert')),

                TrashedFilter::make()->label('المحذوفة'),
            ])
            ->recordActions([
                Action::make('restock')
                    ->label('تعبئة المخزون')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->visible(fn (Product $record) => $record->track_stock)
                    ->schema([
                        TextInput::make('quantity')
                            ->label('الكمية المضافة')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                    ])
                    ->action(function (Product $record, array $data) {
                        $record->increment('stock_quantity', (int) $data['quantity']);
                        $record->update(['is_available' => true]);

                        Notification::make()
                            ->title('تمت التعبئة')
                            ->body('الكمية توّا: '.$record->fresh()->stock_quantity)
                            ->success()
                            ->send();
                    }),

                Action::make('toggleAvailable')
                    ->label(fn (Product $record) => $record->is_available ? 'إخفاء' : 'إتاحة')
                    ->icon(fn (Product $record) => $record->is_available ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Product $record) => $record->is_available ? 'gray' : 'success')
                    ->action(fn (Product $record) => $record->update(['is_available' => ! $record->is_available])),

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
