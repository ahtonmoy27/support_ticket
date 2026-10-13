<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\TicketMessage> */
class TicketMessageFactory extends Factory {
    protected $model = \App\Models\TicketMessage::class;
    public function definition(): array { return ['ticket_id'=>\App\Models\Ticket::factory(),'author_id'=>\App\Models\User::factory(),'body'=>fake()->paragraph(),'visibility'=>'public']; }
}