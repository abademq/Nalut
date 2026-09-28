<?php

namespace App\Filament\Merchant\Resources\Products;

use App\Filament\Merchant\Resources\Products\Pages\CreateProduct;
use App\Filament\Merchant\Resources\Products\Pages\EditProduct;
use App\Filament\Merchant\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Support\Merchant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** أصناف المتجر — نفس فورم لوحة الإدارة، بس مربوط بمتجره هو */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return 'صنف';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الأصناف';
    }

    /** أي صنف مش من متجره = مش موجود (404) */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('store_id', Merchant::storeId());
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema, Merchant::storeId());
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table, merchant: true)->defaultSort('sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
