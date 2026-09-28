<?php

namespace App\Filament\Merchant\Resources\MenuSections;

use App\Filament\Merchant\Resources\MenuSections\Pages\ManageMenuSections;
use App\Models\MenuSection;
use App\Support\Merchant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** أقسام القائمة (شاورما، برجر، مشروبات...) — لمتجره بس */
class MenuSectionResource extends Resource
{
    protected static ?string $model = MenuSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return 'قسم';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أقسام القائمة';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('store_id', Merchant::storeId());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم القسم')->required()->maxLength(60)->placeholder('مشروبات'),
            Toggle::make('is_active')->label('يظهر للزبائن')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('القسم')->weight('bold'),
                TextColumn::make('products_count')->label('عدد الأصناف')->counts('products')->badge(),
                ToggleColumn::make('is_active')->label('يظهر'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')
                    ->modalDescription('الأصناف ما تنحذفش — تولّي بدون قسم.')
                    // الأصناف تنفك من القسم قبل ما ينحذف
                    ->before(fn (MenuSection $record) => $record->products()->update(['menu_section_id' => null])),
            ])
            ->emptyStateHeading('ما فيش أقسام')
            ->emptyStateDescription('الأقسام ترتّب قائمتك عند الزبون (مثلاً: وجبات، مشروبات، حلويات).');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMenuSections::route('/')];
    }
}
