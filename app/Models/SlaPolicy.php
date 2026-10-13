<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class SlaPolicy extends Model {
/** @use HasFactory<\Database\Factories\SlaPolicyFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return ['is_active' => 'boolean']; }
public function calendar(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id'); }
public function rules(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(SlaRule::class); }
}