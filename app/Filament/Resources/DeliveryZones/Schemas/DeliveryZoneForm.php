<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DeliveryZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('اسم المنطقة')->required(),
                TextInput::make('base_fee')->label('الرسم الأساسي (د.ل)')->numeric()->required()->default(5),
                TextInput::make('fee_per_km')->label('رسم كل كيلومتر (د.ل)')->numeric()->required()->default(1.5),
                TextInput::make('min_order')->label('أقل قيمة طلب (د.ل)')->numeric()->required()->default(0),
                TextInput::make('center_lat')->label('خط عرض المركز')->numeric(),
                TextInput::make('center_lng')->label('خط طول المركز')->numeric(),
                TextInput::make('radius_km')->label('نطاق التغطية (كم)')->numeric()->required()->default(10)
                    ->helperText('أي عنوان داخل النطاق هذا يتبع المنطقة تلقائياً'),
                Toggle::make('is_active')->label('مفعّلة')->default(true),
            ]);
    }
}
