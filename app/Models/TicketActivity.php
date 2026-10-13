<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class TicketActivity extends Model {
/** @use HasFactory<\Database\Factories\TicketActivityFactory> */
use HasFactory;
protected $guarded = ['id'];
public $timestamps = false;

protected function casts(): array { return ['old_values' => 'array','new_values' => 'array','created_at' => 'immutable_datetime']; }

protected static function booted(): void { static::updating(fn () => throw new \LogicException('Audit history is immutable.')); static::deleting(fn () => throw new \LogicException('Audit history is immutable.')); }
}