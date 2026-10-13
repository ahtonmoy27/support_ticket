<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class TicketEscalation extends Model {
/** @use HasFactory<\Database\Factories\TicketEscalationFactory> */
use HasFactory;
protected $guarded = ['id'];
public $timestamps = false;

protected function casts(): array { return ['triggered_at' => 'immutable_datetime']; }

}