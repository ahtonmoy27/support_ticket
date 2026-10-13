<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\Ticket> */
class TicketFactory extends Factory {
    protected $model = \App\Models\Ticket::class;
    public function definition(): array { return ['ticket_number'=>'TKT-'.fake()->unique()->numerify('##########'),'customer_id'=>\App\Models\User::factory(),'created_by'=>\App\Models\User::factory(),
'category_id'=>\App\Models\Category::factory(),'subject'=>fake()->sentence(),'description'=>fake()->paragraph(),'priority'=>'medium','status'=>'open',
'sla_rule_id'=>\App\Models\SlaRule::factory(),'sla_target_minutes'=>1440,'sla_warning_percent'=>80,
'sla_calendar_snapshot'=>['timezone'=>'Asia/Dhaka','hours'=>array_fill_keys([0,1,2,3,4],[['09:00','18:00']]),'exceptions'=>[]],
'sla_started_at'=>now(),'sla_warning_at'=>now()->addDay(),'sla_due_at'=>now()->addDays(2)]; }
}