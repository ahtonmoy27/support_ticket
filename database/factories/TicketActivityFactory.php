<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\TicketActivity> */
class TicketActivityFactory extends Factory {
    protected $model = \App\Models\TicketActivity::class;
    public function definition(): array { return ['ticket_id'=>\App\Models\Ticket::factory(),'actor_id'=>\App\Models\User::factory(),'action'=>'ticket.created','visibility'=>'public','old_values'=>[],'new_values'=>[]]; }
}