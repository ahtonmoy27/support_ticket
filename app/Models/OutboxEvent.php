<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class OutboxEvent extends Model {
/** @use HasFactory<\Database\Factories\OutboxEventFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return ['recipient_ids' => 'array','dispatched_at' => 'immutable_datetime']; }
public function ticket(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Ticket::class); }
}