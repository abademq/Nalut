<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل موافقة (مين، على شنو، أي نسخة، من وين، ومتى) — للإثبات القانوني */
class UserConsent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'document', 'version', 'app', 'ip', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
