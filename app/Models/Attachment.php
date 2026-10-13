<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Attachment extends Model {
/** @use HasFactory<\Database\Factories\AttachmentFactory> */
use HasFactory;
protected $guarded = ['id'];

protected $hidden = ['storage_key','disk'];
protected function casts(): array { return []; }
public function ticket(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Ticket::class); }
public function message(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(TicketMessage::class, 'ticket_message_id'); }
}