<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    protected $fillable = [
        'name', 'base_fee', 'fee_per_km', 'min_order',
        'center_lat', 'center_lng', 'radius_km', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'base_fee'   => 'float',
            'fee_per_km' => 'float',
            'min_order'  => 'float',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'radius_km'  => 'float',
            'is_active'  => 'boolean',
        ];
    }
}
