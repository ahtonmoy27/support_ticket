<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\TicketEscalation> */
class TicketEscalationFactory extends Factory {
    protected $model = \App\Models\TicketEscalation::class;
    public function definition(): array { return ['ticket_id'=>\App\Models\Ticket::factory(),'cycle'=>1,'level'=>'warning','triggered_at'=>now()]; }
}