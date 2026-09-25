<?php

namespace App\Filament\Forms;

use App\Models\DeliveryZone;
use Filament\Forms\Components\ViewField;

/**
 * اختيار موقع على الخريطة بدل كتابة الإحداثيات بيدك.
 *
 * يكتب في حقلين موجودين في نفس الفورم (lat/lng) — الحقلين يضلو ظاهرين
 * للّصق اليدوي، والخريطة تتحرك معاهم. مع radiusField يرسم دائرة التغطية.
 */
class MapPicker
{
    public static function make(
        string $latField = 'lat',
        string $lngField = 'lng',
        ?string $radiusField = null,
        bool $showZones = false,
        ?string $label = null,
    ): ViewField {
        return ViewField::make('map_picker_'.$latField)
            ->label($label ?? 'الموقع على الخريطة')
            ->view('filament.forms.map-picker')
            ->viewData([
                'latField'    => $latField,
                'lngField'    => $lngField,
                'radiusField' => $radiusField,
                // مناطق التوصيل كمرجع رمادي على الخريطة
                'zones'       => $showZones
                    ? DeliveryZone::query()
                        ->whereNotNull('center_lat')->whereNotNull('center_lng')
                        ->get(['id', 'name', 'center_lat', 'center_lng', 'radius_km', 'is_active'])
                        ->map(fn ($z) => [
                            'id'     => $z->id,
                            'name'   => $z->name,
                            'lat'    => (float) $z->center_lat,
                            'lng'    => (float) $z->center_lng,
                            'radius' => (float) $z->radius_km,
                            'active' => (bool) $z->is_active,
                        ])->values()->all()
                    : [],
            ])
            ->dehydrated(false)
            ->columnSpanFull();
    }
}
