<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\MenuSection;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('store_id')
                    ->label('المتجر')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->required()
                    ->live(),

                Select::make('menu_section_id')
                    ->label('القسم')
                    ->options(fn ($get) => $get('store_id')
                        ? MenuSection::where('store_id', $get('store_id'))->pluck('name', 'id')
                        : [])
                    ->searchable()
                    ->helperText('اختار المتجر أول'),

                TextInput::make('name')
                    ->label('اسم المنتج')
                    ->required(),

                TextInput::make('price')
                    ->label('السعر')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->suffix('د.ل'),

                TextInput::make('discount_price')
                    ->label('سعر العرض')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('د.ل')
                    ->helperText('اتركه فاضي لو ما فيش عرض'),

                TextInput::make('sort')
                    ->label('الترتيب في القائمة')
                    ->numeric()
                    ->default(0),

                Textarea::make('description')
                    ->label('الوصف')
                    ->rows(3)
                    ->columnSpanFull(),

                FileUpload::make('images')
                    ->label('صور المنتج')
                    ->helperText('الصورة الأولى هي الرئيسية — اسحب الصور لتغيير الترتيب.')
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->appendFiles()
                    ->maxFiles(\App\Models\Product::MAX_IMAGES)
                    ->maxSize(5120)
                    ->disk('public')
                    ->directory('products')
                    ->columnSpanFull(),

                Toggle::make('is_available')
                    ->label('متوفر للطلب')
                    ->default(true)
                    ->columnSpanFull(),

                // ===== المخزون =====
                Toggle::make('track_stock')
                    ->label('تتبّع الكمية في المخزن')
                    ->default(false)
                    ->live()
                    ->columnSpanFull()
                    ->helperText('فعّلها للمنتجات المحدودة (مثل الحلويات أو البضاعة). '
                        .'اتركها مقفولة للمنتجات اللي تتحضّر عند الطلب — تكون متوفرة دائماً.'),

                TextInput::make('stock_quantity')
                    ->label('الكمية المتوفرة')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->visible(fn ($get) => $get('track_stock'))
                    ->helperText('تنقص تلقائياً مع كل طلب، والمنتج يختفي لمّا توصل صفر'),

                TextInput::make('low_stock_alert')
                    ->label('تنبيه عند الكمية')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn ($get) => $get('track_stock'))
                    ->helperText('يظهر تنبيه لمّا الكمية تنزل لهذا الرقم'),

                TextInput::make('max_per_order')
                    ->label('أقصى كمية في الطلب الواحد')
                    ->numeric()
                    ->minValue(1)
                    ->columnSpanFull()
                    ->helperText('اتركه فاضي = بدون حد'),
            ]);
    }
}
