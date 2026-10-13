<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketEscalation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketEscalation> */
class TicketEscalationFactory extends Factory
{
    protected $model = TicketEscalation::class;

    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'cycle' => 1, 'level' => 'warning', 'triggered_at' => now()];
    }
}
