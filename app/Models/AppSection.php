<?php

namespace App\Models;

use App\Models\Concerns\HasBlurHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** قسم رئيسي في تطبيق الزبون (مطاعم، متاجر إلكترونية، ...) — يجمع أنواع متاجر */
class AppSection extends Model
{
    use HasBlurHashes;

    protected const BLURHASH = ['image' => 'image_hash'];

    protected $fillable = ['name', 'subtitle', 'emoji', 'image', 'color', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function types(): HasMany
    {
        return $this->hasMany(StoreType::class);
    }

    public function toApp(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'emoji' => $this->emoji,
            'image' => $this->image ? asset('storage/'.$this->image) : null,
            'blurhash' => $this->image ? $this->image_hash : null,
            'color' => $this->color,
            'type_ids' => $this->types->pluck('id')->all(),
        ];
    }
}
