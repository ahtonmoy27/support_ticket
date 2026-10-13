<?php

namespace Database\Factories;

use App\Models\SlaPolicy;
use App\Models\SlaRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaRule> */
class SlaRuleFactory extends Factory
{
    protected $model = SlaRule::class;

    public function definition(): array
    {
        return ['sla_policy_id' => SlaPolicy::factory(), 'priority' => 'medium', 'resolution_minutes' => 1440, 'warning_percent' => 80];
    }
}
