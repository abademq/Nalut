<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use App\Filament\Forms\MapPicker;
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
                // اضغط على الخريطة لتحديد المركز، والدائرة تبيّن التغطية حسب النطاق
                MapPicker::make('center_lat', 'center_lng', 'radius_km', true, 'مركز المنطقة ونطاقها'),
                TextInput::make('center_lat')->label('خط عرض المركز')->numeric()->required()
                    ->validationMessages(['required' => 'حدّد مركز المنطقة على الخريطة.']),
                TextInput::make('center_lng')->label('خط طول المركز')->numeric()->required()
                    ->validationMessages(['required' => 'حدّد مركز المنطقة على الخريطة.']),
                TextInput::make('radius_km')->label('نطاق التغطية (كم)')->numeric()->required()->default(10)
                    ->minValue(0.5)->maxValue(100)->step(0.5)
                    ->live(debounce: 400)
                    ->helperText('الدائرة على الخريطة تتغيّر معاه — أي عنوان داخلها يتبع المنطقة'),
                Toggle::make('is_active')->label('مفعّلة')->default(true),
            ]);
    }
}
