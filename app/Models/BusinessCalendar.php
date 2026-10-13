<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class BusinessCalendar extends Model {
/** @use HasFactory<\Database\Factories\BusinessCalendarFactory> */
use HasFactory;
protected $guarded = ['id'];


protected function casts(): array { return ['hours' => 'array','exceptions' => 'array']; }

}