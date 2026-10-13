<?php

namespace App\Models;

use Database\Factories\BusinessCalendarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessCalendar extends Model
{
    /** @use HasFactory<BusinessCalendarFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hours' => 'array', 'exceptions' => 'array'];
    }
}
