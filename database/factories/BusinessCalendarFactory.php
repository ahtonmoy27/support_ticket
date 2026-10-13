<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessCalendar> */
class BusinessCalendarFactory extends Factory
{
    protected $model = BusinessCalendar::class;

    public function definition(): array
    {
        return ['name' => 'Dhaka business hours', 'timezone' => 'Asia/Dhaka', 'hours' => array_fill_keys([0, 1, 2, 3, 4], [['09:00', '18:00']]), 'exceptions' => []];
    }
}
