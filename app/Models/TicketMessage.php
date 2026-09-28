<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'is_staff', 'body', 'image'];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    public function toApp(): array
    {
        return [
            'id' => $this->id,
            // الإدارة تطلع باسم «الدعم الفني» — ما نكشفوش اسم الموظف
            'from' => $this->is_staff ? 'staff' : 'me',
            'sender' => $this->is_staff ? 'الدعم الفني' : null,
            'body' => $this->body,
            'image' => $this->imageUrl(),
            'created_at' => $this->created_at,
        ];
    }
}
