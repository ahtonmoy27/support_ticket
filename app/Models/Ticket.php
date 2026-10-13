<?php

namespace App\Models;

use App\Domain\Tickets\Enums\TicketPriority;
use App\Domain\Tickets\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['priority' => TicketPriority::class, 'status' => TicketStatus::class, 'sla_calendar_snapshot' => 'array', 'sla_started_at' => 'immutable_datetime', 'sla_warning_at' => 'immutable_datetime', 'sla_due_at' => 'immutable_datetime', 'sla_breached_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function slaRule(): BelongsTo
    {
        return $this->belongsTo(SlaRule::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(TicketEscalation::class);
    }
}
