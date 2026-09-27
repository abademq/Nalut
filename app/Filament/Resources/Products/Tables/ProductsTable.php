<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\ShareLink;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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

                // حالة وحدة واضحة بدل «متوفر ✓/✗»: متوفر · قرّب يخلص · نفد (يرجع مع الكمية) · موقوف (بالإيد)
                TextColumn::make('state')
                    ->label('الحالة')
                    ->badge()
                    ->state(fn (Product $record) => $record->state())
                    ->formatStateUsing(fn ($state) => Product::STATE_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'available' => 'success', 'low' => 'warning', 'sold_out' => 'danger', default => 'gray',
                    })
                    ->tooltip(fn (Product $record) => match ($record->state()) {
                        'sold_out' => 'الكمية صفر — يتفتح لحاله أول ما تزيد الكمية',
                        'stopped' => 'مقفول بالإيد — يقعد مقفول لين تفتحه',
                        default => null,
                    }),
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
                ShareLink::make(fn ($record) => route('link.product', ['store' => $record->store_id, 'product' => $record->id]))
                    ->iconButton()->tooltip('رابط المشاركة'),
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
                    ->label(fn (Product $record) => $record->is_available ? 'إيقاف' : 'فتح للطلب')
                    ->icon(fn (Product $record) => $record->is_available ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Product $record) => $record->is_available ? 'gray' : 'success')
                    ->action(function (Product $record) {
                        // خالص: ما يتفتحش إلا بعد «تعبئة المخزون»
                        if (! $record->is_available && $record->isOutOfStock()) {
                            Notification::make()->title('المنتج خلص')->body(Product::OUT_OF_STOCK_MESSAGE)->warning()->send();

                            return;
                        }
                        $record->update(['is_available' => ! $record->is_available]);
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
