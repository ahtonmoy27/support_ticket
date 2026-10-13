<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaPolicy> */
class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    public function definition(): array
    {
        return ['name' => 'Default', 'business_calendar_id' => BusinessCalendar::factory(), 'version' => 1, 'is_active' => true];
    }
}
