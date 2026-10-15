<?php

namespace Tests\Feature;

use App\Domain\Audit\Services\RecordActivity;
use App\Jobs\DeliverOutboxEvent;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OutboxCommitTest extends TestCase
{
    use DatabaseMigrations;

    public function test_notification_dispatch_waits_for_outer_commit(): void
    {
        Bus::fake();
        $ticket = Ticket::factory()->create();
        DB::beginTransaction();
        app(RecordActivity::class)->execute($ticket, null, 'ticket.updated');
        Bus::assertNotDispatched(DeliverOutboxEvent::class);
        DB::commit();
        Bus::assertDispatched(DeliverOutboxEvent::class, fn (DeliverOutboxEvent $job): bool => $job->queue === config('support.notifications_queue'));
    }

    public function test_rollback_does_not_dispatch_or_persist_outbox_event(): void
    {
        Bus::fake();
        $ticket = Ticket::factory()->create();
        DB::beginTransaction();
        app(RecordActivity::class)->execute($ticket, null, 'ticket.updated');
        DB::rollBack();
        Bus::assertNotDispatched(DeliverOutboxEvent::class);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_queue_outage_preserves_committed_ticket_event_for_scheduler_recovery(): void
    {
        Log::spy();
        $ticket = Ticket::factory()->create();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        DB::transaction(function () use ($ticket): void {
            app(RecordActivity::class)->execute($ticket, null, 'ticket.updated');
        });
        $this->assertDatabaseHas('outbox_events', ['ticket_id' => $ticket->id, 'dispatched_at' => null]);
        $this->assertDatabaseCount('ticket_activities', 1);
    }
}
