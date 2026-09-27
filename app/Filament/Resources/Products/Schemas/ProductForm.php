<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\MenuSection;
use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                    ->maxFiles(Product::MAX_IMAGES)
                    ->maxSize(5120)
                    ->disk('public')
                    ->directory('products')
                    ->columnSpanFull(),

                Toggle::make('is_visible')
                    ->label('يظهر للزبائن')
                    ->default(true)
                    ->helperText('مطفي = الصنف مخفي كامل عن الزبائن (ما يبانش في القائمة)، بعيد عن توفّره.')
                    ->columnSpanFull(),

                Toggle::make('is_available')
                    ->label('متوفر للطلب')
                    ->default(true)
                    // منتج بكمية وخلص: يتفتح تلقائياً أول ما تزيد الكمية
                    ->disabled(fn ($get) => $get('track_stock') && (int) $get('stock_quantity') <= 0)
                    ->helperText(fn ($get) => $get('track_stock') && (int) $get('stock_quantity') <= 0
                        ? 'نفد: الكمية صفر — زيد الكمية والمنتج يتفتح تلقائياً.'
                        : 'مطفي = «موقوف»: يبان للزبائن «مش متوفر» ويقعد مقفول لين تفتحه أنت.')
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
                    ->live(onBlur: true)
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->visible(fn ($get) => $get('track_stock'))
                    ->helperText('تنقص تلقائياً مع كل طلب، ولمّا توصل صفر المنتج يتقفل للطلب (يقعد يبان للزبائن بعلامة «نفد»)'),

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

                // ===== الإضافات والخيارات =====
                Section::make('الإضافات والخيارات')
                    ->description('مثال: «الإضافات» (زيادة صوص، سيخ كباب إضافي) أو «الحجم» (صغير/وسط/كبير). الزبون يختار منها في التطبيق.')
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->relationship('options')
                            ->orderColumn('sort')
                            ->collapsible()
                            ->itemLabel(fn (array $state) => $state['name'] ?? null)
                            ->addActionLabel('مجموعة جديدة')
                            ->columns(4)
                            ->schema([
                                TextInput::make('name')
                                    ->label('اسم المجموعة')
                                    ->placeholder('الإضافات')
                                    ->required()
                                    ->maxLength(60)
                                    ->columnSpan(2),
                                Select::make('type')
                                    ->label('النوع')
                                    ->options(['multi' => 'يختار أكثر من وحدة', 'single' => 'يختار وحدة بس'])
                                    ->default('multi')
                                    ->required()
                                    ->live(),
                                Toggle::make('is_required')
                                    ->label('إجباري')
                                    ->inline(false),
                                TextInput::make('max_choices')
                                    ->label('أقصى عدد إضافات مختلفة')
                                    ->numeric()->minValue(1)->default(5)
                                    ->visible(fn ($get) => $get('type') === 'multi')
                                    ->dehydrateStateUsing(fn ($state, $get) => $get('type') === 'single' ? 1 : max(1, (int) $state)),
                                Repeater::make('values')
                                    ->label('العناصر')
                                    ->relationship('values')
                                    ->orderColumn('sort')
                                    ->addActionLabel('عنصر جديد')
                                    ->columns(4)
                                    ->columnSpanFull()
                                    ->minItems(1)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('الاسم')
                                            ->placeholder('زيادة صوص')
                                            ->required()
                                            ->maxLength(60),
                                        TextInput::make('extra_price')
                                            ->label('السعر الإضافي')
                                            ->numeric()->minValue(0)->default(0)
                                            ->suffix('د.ل')
                                            ->helperText('0 = مجاناً'),
                                        TextInput::make('max_qty')
                                            ->label('أقصى عدد')
                                            ->numeric()->minValue(1)->maxValue(20)->default(1)
                                            ->helperText('1 = مرة وحدة · أكثر = يتزاد (سيخ × 3)'),
                                        Toggle::make('is_available')
                                            ->label('متوفر')
                                            ->default(true)
                                            ->inline(false),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
