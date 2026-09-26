<?php

namespace App\Filament\Resources\StoreTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StoreTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('النوع')->searchable()->weight('bold'),
                TextColumn::make('section.name')->label('القسم')->badge()->placeholder('—'),
                TextColumn::make('stores_count')->label('عدد المتاجر')->counts('stores')->badge(),
                TextColumn::make('sort')->label('الترتيب')->sortable(),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                EditAction::make()->label('تعديل'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()->label('حذف')]),
            ]);
    }
}
