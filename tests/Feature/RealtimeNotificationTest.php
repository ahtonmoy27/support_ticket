<?php

namespace Tests\Feature;

use App\Domain\Audit\Services\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Channels\DatabaseChannel;
use App\Notifications\TicketChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RealtimeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_dispatch_by_worker_and_scheduler_does_not_enqueue_twice(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['customer_id' => $user->id]);
        $event = OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => 'ticket.updated', 'recipient_ids' => [$user->id]]);
        $dispatcher = app(DispatchOutboxEvent::class);
        $dispatcher->execute($event->id);
        $this->artisan('tickets:dispatch-outbox')->assertSuccessful();
        Notification::assertSentToTimes($user, TicketChanged::class, 1);
        $this->assertNotNull($event->fresh()->dispatched_at);
    }

    public function test_broadcast_uses_notifications_queue_and_log_driver_does_not_claim_live_delivery(): void
    {
        $ticket = Ticket::factory()->create();
        $notification = new TicketChanged($ticket->id, 'ticket.updated', 1);
        config(['broadcasting.default' => 'pusher', 'support.notifications_queue' => 'realtime']);
        $this->assertContains('broadcast', $notification->via($ticket->customer));
        $this->assertSame('realtime', $notification->toBroadcast($ticket->customer)->queue);
        config(['broadcasting.default' => 'log']);
        $this->assertSame([DatabaseChannel::class, 'mail'], $notification->via($ticket->customer));
    }

    public function test_private_channel_cannot_be_authorized_for_another_user(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.($user->id + 1)])->assertForbidden();
    }
}
