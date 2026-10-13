<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\OutboxEvent> */
class OutboxEventFactory extends Factory {
    protected $model = \App\Models\OutboxEvent::class;
    public function definition(): array { return ['ticket_id'=>\App\Models\Ticket::factory(),'event_type'=>'ticket.created','recipient_ids'=>[]]; }
}