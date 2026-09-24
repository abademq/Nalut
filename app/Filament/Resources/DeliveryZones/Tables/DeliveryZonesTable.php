<?php

namespace App\Filament\Resources\DeliveryZones\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeliveryZonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('اسم المنطقة')->searchable()->weight('bold'),
                TextColumn::make('base_fee')->label('الرسم الأساسي')->money('LYD')->sortable(),
                TextColumn::make('fee_per_km')->label('لكل كم')->money('LYD')->sortable(),
                TextColumn::make('min_order')->label('أقل طلب')->money('LYD'),
                TextColumn::make('radius_km')->label('نطاق التغطية')->suffix(' كم'),
                IconColumn::make('is_active')->label('مفعّلة')->boolean(),
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
