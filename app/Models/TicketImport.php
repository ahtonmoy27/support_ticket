<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class TicketImport extends Model {
/** @use HasFactory<\Database\Factories\TicketImportFactory> */
use HasFactory;
protected $guarded = ['id'];

protected $hidden = ['storage_key'];
protected function casts(): array { return []; }
public function rows(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(ImportRow::class); }
public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}