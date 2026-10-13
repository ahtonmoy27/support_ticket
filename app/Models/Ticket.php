<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Ticket extends Model {
/** @use HasFactory<\Database\Factories\TicketFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return ['priority' => \App\Domain\Tickets\Enums\TicketPriority::class,'status' => \App\Domain\Tickets\Enums\TicketStatus::class,'sla_calendar_snapshot' => 'array','sla_started_at' => 'immutable_datetime','sla_warning_at' => 'immutable_datetime','sla_due_at' => 'immutable_datetime','sla_breached_at' => 'immutable_datetime','resolved_at' => 'immutable_datetime','closed_at' => 'immutable_datetime']; }
public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
public function assignedAgent(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'assigned_agent_id'); }
public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Category::class); }
public function slaRule(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(SlaRule::class); }
public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(TicketMessage::class); }
public function activities(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(TicketActivity::class); }
public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Attachment::class); }
public function escalations(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(TicketEscalation::class); }
}