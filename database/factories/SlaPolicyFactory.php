<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\SlaPolicy> */
class SlaPolicyFactory extends Factory {
    protected $model = \App\Models\SlaPolicy::class;
    public function definition(): array { return ['name'=>'Default','business_calendar_id'=>\App\Models\BusinessCalendar::factory(),'version'=>1,'is_active'=>true]; }
}