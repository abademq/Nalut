<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['channel', 'phone', 'message_template_id', 'context', 'context_id', 'status', 'provider_id', 'error', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public const CONTEXTS = ['otp' => 'رمز تحقق', 'campaign' => 'حملة', 'report' => 'تقرير', 'alert' => 'تنبيه'];

    public const STATUSES = ['sent' => 'انبعتت', 'failed' => 'فشلت', 'skipped' => 'متخطّاة'];

    public function template()
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
