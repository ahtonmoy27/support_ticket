<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\SlaRule> */
class SlaRuleFactory extends Factory {
    protected $model = \App\Models\SlaRule::class;
    public function definition(): array { return ['sla_policy_id'=>\App\Models\SlaPolicy::factory(),'priority'=>'medium','resolution_minutes'=>1440,'warning_percent'=>80]; }
}