<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Category extends Model {
/** @use HasFactory<\Database\Factories\CategoryFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return ['is_active' => 'boolean']; }
public function agents(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(User::class, 'agent_categories'); }
}