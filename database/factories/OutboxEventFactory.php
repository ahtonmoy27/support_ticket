<?php

namespace Database\Factories;

use App\Models\OutboxEvent;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OutboxEvent> */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'event_type' => 'ticket.created', 'recipient_ids' => []];
    }
}
