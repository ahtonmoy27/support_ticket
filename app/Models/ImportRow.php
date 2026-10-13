<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class ImportRow extends Model {
/** @use HasFactory<\Database\Factories\ImportRowFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return ['errors' => 'array']; }

}