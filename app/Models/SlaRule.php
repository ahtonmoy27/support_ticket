<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class SlaRule extends Model {
/** @use HasFactory<\Database\Factories\SlaRuleFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return []; }
public function policy(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(SlaPolicy::class, 'sla_policy_id'); }
}