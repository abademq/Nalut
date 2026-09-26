<?php

namespace App\Filament\Resources\Engagement;

use App\Models\Product;
use App\Models\ReadyCart;
use App\Models\Store;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** سلات جاهزة: مجموعة أصناف من متجر، الزبون يضيفها للسلة بضغطة */
class ReadyCartResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'products.manage';

    public const PERM_MANAGE = 'products.manage';

    protected static ?string $model = ReadyCart::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'الكتالوج';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'ready-carts';

    public static function getModelLabel(): string
    {
        return 'سلة جاهزة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'السلات الجاهزة';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('store_id')->label('المتجر')->required()->native(false)->searchable()->live()
                ->options(fn () => Store::orderBy('name')->pluck('name', 'id')),
            TextInput::make('name')->label('اسم السلة')->required()->maxLength(80)->placeholder('سلة الفطور العائلية'),
            TextInput::make('description')->label('وصف قصير')->maxLength(160)->columnSpanFull(),
            FileUpload::make('image')->label('صورة (اختياري)')->image()->disk('public')->directory('ready-carts')->maxSize(2048),
            TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
            Repeater::make('items')->label('الأصناف')->required()->minItems(1)->columnSpanFull()->columns(3)
                ->schema([
                    Select::make('product_id')->label('الصنف')->required()->native(false)->searchable()->columnSpan(2)
                        ->options(fn ($get) => Product::where('store_id', $get('../../store_id'))->orderBy('name')
                            ->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->name} — ".number_format($p->effectivePrice(), 2).' د.ل'])),
                    TextInput::make('quantity')->label('الكمية')->numeric()->required()->default(1)->minValue(1),
                ])
                ->addActionLabel('زيد صنف'),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('السلة')->weight('bold')->searchable()
                    ->description(fn (ReadyCart $r) => $r->store?->name),
                TextColumn::make('items')->label('الأصناف')->state(fn (ReadyCart $r) => count($r->lines())),
                TextColumn::make('price')->label('السعر الحالي')->state(fn (ReadyCart $r) => number_format($r->price(), 2).' د.ل'),
                IconColumn::make('is_active')->label('مفعّلة')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل'), DeleteAction::make()->label('حذف')]);
    }

    public static function getPages(): array
    {
        return ['index' => ReadyCartResource\ManageReadyCarts::route('/')];
    }
}
