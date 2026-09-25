<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailureReason extends Model
{
    protected $fillable = ['label', 'hold_for_review', 'open_support', 'sort', 'is_active'];

    protected function casts(): array
    {
        return [
            'hold_for_review' => 'boolean',
            'open_support'    => 'boolean',
            'is_active'       => 'boolean',
        ];
    }
}
