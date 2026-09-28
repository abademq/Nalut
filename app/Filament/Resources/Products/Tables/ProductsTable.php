<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\ShareLink;
use App\Models\Product;
use App\Support\Merchant;
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
    /** @param  bool  $merchant  لوحة المتجر: بدون عمود/فلتر المتجر والمحذوفات */
    public static function configure(Table $table, bool $merchant = false): Table
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
                    ->badge()
                    ->visible(! $merchant),

                TextColumn::make('section.name')
                    ->label('القسم')
                    ->placeholder('—')
                    ->visible($merchant)
                    ->visibleFrom('md'),

                TextColumn::make('price')
                    ->label('السعر')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->sortable(),

                TextColumn::make('discount_price')
                    ->label('سعر العرض')
                    // على الموبايل (لوحة المتجر) نخلّيو الأهم: الاسم والسعر والحالة
                    ->visibleFrom($merchant ? 'md' : null)
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

                IconColumn::make('is_visible')
                    ->label('يظهر')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye')
                    ->falseIcon('heroicon-o-eye-slash'),

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
            ->filters(array_values(array_filter([
                $merchant ? SelectFilter::make('menu_section_id')
                    ->label('القسم')
                    ->relationship('section', 'name', fn ($query) => $query->where('store_id', Merchant::storeId()))
                    : SelectFilter::make('store_id')
                        ->label('المتجر')
                        ->relationship('store', 'name')
                        ->searchable(),

                Filter::make('hidden')
                    ->label('المخفية عن الزبائن')
                    ->query(fn ($query) => $query->where('is_visible', false)),

                Filter::make('low_stock')
                    ->label('مخزون منخفض')
                    ->query(fn ($query) => $query->where('track_stock', true)
                        ->whereNotNull('low_stock_alert')
                        ->whereColumn('stock_quantity', '<=', 'low_stock_alert')),

                $merchant ? null : TrashedFilter::make()->label('المحذوفة'),
            ])))
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

                Action::make('toggleVisible')
                    ->label(fn (Product $record) => $record->is_visible ? 'إخفاء عن الزبائن' : 'إظهار للزبائن')
                    ->icon(fn (Product $record) => $record->is_visible ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color('gray')
                    ->action(fn (Product $record) => $record->update(['is_visible' => ! $record->is_visible])),

                Action::make('toggleAvailable')
                    ->label(fn (Product $record) => $record->is_available ? 'إيقاف' : 'فتح للطلب')
                    ->icon(fn (Product $record) => $record->is_available ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn (Product $record) => $record->is_available ? 'gray' : 'success')
                    ->action(function (Product $record) {
                        // خالص: ما يتفتحش إلا بعد «تعبئة المخزون»
                        if (! $record->is_available && $record->isOutOfStock()) {
                            Notification::make()->title('المنتج خلص')->body(Product::OUT_OF_STOCK_MESSAGE)->warning()->send();

                            return;
                        }
                        $record->update(['is_available' => ! $record->is_available]);
                    }),

                ViewAction::make()->label('عرض')->visible(! $merchant),
                EditAction::make()->label('تعديل'),
            ])
            ->toolbarActions([
                BulkActionGroup::make(array_values(array_filter([
                    DeleteBulkAction::make()->label('حذف'),
                    $merchant ? null : RestoreBulkAction::make()->label('استرجاع'),
                ]))),
            ]);
    }
}
