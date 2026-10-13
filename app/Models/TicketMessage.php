<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class TicketMessage extends Model {
/** @use HasFactory<\Database\Factories\TicketMessageFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return []; }
public function ticket(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Ticket::class); }
public function author(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}