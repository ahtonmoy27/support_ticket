<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\SlaRule;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return ['ticket_number' => 'TKT-'.fake()->unique()->numerify('##########'), 'customer_id' => User::factory(), 'created_by' => User::factory(),
            'category_id' => Category::factory(), 'subject' => fake()->sentence(), 'description' => fake()->paragraph(), 'priority' => 'medium', 'status' => 'open',
            'sla_rule_id' => SlaRule::factory(), 'sla_target_minutes' => 1440, 'sla_warning_percent' => 80,
            'sla_calendar_snapshot' => ['timezone' => 'Asia/Dhaka', 'hours' => array_fill_keys([0, 1, 2, 3, 4], [['09:00', '18:00']]), 'exceptions' => []],
            'sla_started_at' => now(), 'sla_warning_at' => now()->addDay(), 'sla_due_at' => now()->addDays(2)];
    }
}
