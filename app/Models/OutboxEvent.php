<?php

namespace App\Models;

use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recipient_ids' => 'array', 'payload' => 'array', 'dispatched_at' => 'immutable_datetime'];
    }

    public function isInternal(): bool
    {
        return ($this->payload['visibility'] ?? null) === 'internal'
            || $this->event_type === 'ticket.internal_note';
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
