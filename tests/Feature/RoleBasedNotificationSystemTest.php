<?php

namespace Tests\Feature;

use App\Domain\Assignment\Actions\AssignTicket;
use App\Domain\Audit\Services\DispatchOutboxEvent;
use App\Domain\SLA\Services\SlaEvaluationService;
use App\Domain\Tickets\Actions\AddTicketMessage;
use App\Domain\Tickets\Actions\ChangeTicketStatus;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Jobs\DeliverOutboxEvent;
use App\Models\Category;
use App\Models\OutboxEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InternalNoteNotification;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketNotification;
use App\Notifications\TicketReassignedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketStatusChangedNotification;
use Database\Seeders\SupportSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RoleBasedNotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
    }

    private function createCustomer(): User
    {
        return User::factory()->create(['role' => 'customer', 'is_active' => true]);
    }

    private function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function createAgent(?int $categoryId = null): User
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'is_available' => true]);
        $catId = $categoryId ?? Category::first()->id;
        $agent->categories()->attach($catId);

        return $agent;
    }

    private function createTicket(User $customer, array $extra = []): Ticket
    {
        return app(CreateTicket::class)->execute($customer, array_replace([
            'subject' => 'Help with login',
            'description' => 'I cannot log in to my account.',
            'category_id' => Category::first()->id,
            'priority' => 'critical',
        ], $extra));
    }

    private function dispatchAllOutbox(): void
    {
        $this->artisan('tickets:dispatch-outbox')->assertSuccessful();
    }

    /** 1. Customer receives a ticket creation confirmation. */
    public function test_customer_receives_ticket_creation_confirmation(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();

        $ticket = $this->createTicket($customer);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($customer, TicketCreatedNotification::class, 1);
        Notification::assertSentTo($customer, TicketCreatedNotification::class, function (TicketCreatedNotification $n) use ($ticket) {
            return $n->ticketId === $ticket->id;
        });
    }

    /** 2. An authorized administrator receives ticket-created notifications when enabled. */
    public function test_authorized_administrator_receives_ticket_created_notifications_when_enabled(): void
    {
        Notification::fake();
        config(['support.notifications.admin.ticket_created' => true]);

        $customer = $this->createCustomer();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($admin, TicketCreatedNotification::class, 1);

        // When disabled, admin is not notified
        config(['support.notifications.admin.ticket_created' => false]);
        $customer2 = $this->createCustomer();
        $this->createTicket($customer2);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($admin, TicketCreatedNotification::class, 1);
    }

    /** 3. Only the assigned agent receives the assignment notification. */
    public function test_only_the_assigned_agent_receives_the_assignment_notification(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agentA = $this->createAgent();
        $agentB = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agentA->id);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($agentA, TicketAssignedNotification::class, 1);
        Notification::assertNotSentTo($agentB, TicketAssignedNotification::class);
    }

    /** 4. Customer reply notifies the assigned agent and does not notify the customer. */
    public function test_customer_reply_notifies_the_assigned_agent(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);

        app(AddTicketMessage::class)->execute($ticket->id, $customer, [
            'body' => 'Here is more information about my issue.',
            'visibility' => 'public',
        ]);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($agent, TicketReplyNotification::class, 1);
        Notification::assertNotSentTo($customer, TicketReplyNotification::class);
    }

    /** 5. Agent public reply notifies the ticket-owning customer. */
    public function test_agent_public_reply_notifies_the_ticket_owning_customer(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $otherAgent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);

        app(AddTicketMessage::class)->execute($ticket->id, $agent, [
            'body' => 'We are working on your issue.',
            'visibility' => 'public',
        ]);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($customer, TicketReplyNotification::class, 1);
        Notification::assertNotSentTo($otherAgent, TicketReplyNotification::class);
        Notification::assertNotSentTo($agent, TicketReplyNotification::class);
    }

    /** 6. Internal notes never notify or expose content to customers. */
    public function test_internal_notes_never_notify_or_expose_content_to_customers(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);

        app(AddTicketMessage::class)->execute($ticket->id, $admin, [
            'body' => 'Confidential internal note for engineering team.',
            'visibility' => 'internal',
        ]);
        $this->dispatchAllOutbox();

        Notification::assertNotSentTo($customer, InternalNoteNotification::class);

        // Verify shouldSend prevents customer delivery even if directly attempted
        $noteNotification = new InternalNoteNotification($ticket->id, 'ticket.internal_note', 999);
        $this->assertFalse($noteNotification->shouldSend($customer, 'mail'));
        $this->assertFalse($noteNotification->shouldSend($customer, 'database'));

        // Customer cannot access internal note via API
        $this->actingAs($customer)
            ->getJson("/api/v1/tickets/{$ticket->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertDontSee('Confidential internal note');
    }

    /** 7. Ticket resolution notifies the customer and avoids redundant notification to actor. */
    public function test_ticket_resolution_notifies_the_customer(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);

        app(ChangeTicketStatus::class)->execute($ticket->id, $agent, TicketStatus::Resolved);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($customer, TicketStatusChangedNotification::class, 1);
        Notification::assertNotSentTo($agent, TicketStatusChangedNotification::class);
    }

    /** 8. SLA approaching notifies the appropriate recipients. */
    public function test_sla_approaching_notifies_the_appropriate_recipients(): void
    {
        Notification::fake();
        config([
            'support.notifications.admin.sla_warning' => true,
            'support.notifications.customer.sla_warning' => false,
        ]);

        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);

        $ticket->update([
            'status' => TicketStatus::InProgress,
            'sla_warning_at' => now()->subMinutes(5),
            'sla_due_at' => now()->addMinutes(30),
        ]);

        app(SlaEvaluationService::class)->execute();
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($agent, SlaApproachingNotification::class, 1);
        Notification::assertSentToTimes($admin, SlaApproachingNotification::class, 1);
        Notification::assertNotSentTo($customer, SlaApproachingNotification::class);
    }

    /** 9. SLA breach notifies the appropriate recipients and records escalation. */
    public function test_sla_breach_notifies_the_appropriate_recipients_and_records_escalation(): void
    {
        Notification::fake();
        config([
            'support.notifications.admin.sla_breach' => true,
            'support.notifications.customer.sla_breach' => false,
        ]);

        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);

        $ticket->update([
            'status' => TicketStatus::InProgress,
            'sla_warning_at' => now()->subMinutes(60),
            'sla_due_at' => now()->subMinutes(10),
        ]);

        app(SlaEvaluationService::class)->execute();
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($agent, SlaBreachedNotification::class, 1);
        Notification::assertSentToTimes($admin, SlaBreachedNotification::class, 1);
        Notification::assertNotSentTo($customer, SlaBreachedNotification::class);

        $this->assertDatabaseHas('ticket_escalations', [
            'ticket_id' => $ticket->id,
            'level' => 'breach',
            'cycle' => 1,
        ]);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'sla.breach',
        ]);
    }

    /** 10. Reassignment notifies the old and new agents according to the rules. */
    public function test_reassignment_notifies_the_old_and_new_agents(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agentA = $this->createAgent();
        $agentB = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agentA->id);
        $this->dispatchAllOutbox();

        // Reassign to agent B
        app(AssignTicket::class)->execute($ticket->id, $admin, $agentB->id);
        $this->dispatchAllOutbox();

        Notification::assertSentToTimes($agentB, TicketAssignedNotification::class, 1);
        Notification::assertSentToTimes($agentA, TicketReassignedNotification::class, 1);
    }

    /** 11. Saving an unchanged assignment or status does not generate duplicate notifications. */
    public function test_saving_an_unchanged_assignment_does_not_generate_duplicate_notifications(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);
        $this->dispatchAllOutbox();
        Notification::assertSentToTimes($agent, TicketAssignedNotification::class, 1);

        // Assign same agent again
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);
        $this->dispatchAllOutbox();
        Notification::assertSentToTimes($agent, TicketAssignedNotification::class, 1);
    }

    /** 12. A user cannot read or modify another user's notifications. */
    public function test_user_cannot_read_modify_or_delete_another_users_notifications(): void
    {
        $userA = $this->createCustomer();
        $userB = $this->createCustomer();

        // Create notification directly for user A
        $notificationA = $userA->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => TicketCreatedNotification::class,
            'data' => ['ticket_id' => 1, 'event' => 'ticket.created', 'event_id' => 1],
            'read_at' => null,
        ]);

        // User B tries to read User A's notification
        $this->actingAs($userB)
            ->patchJson("/api/v1/notifications/{$notificationA->id}/read")
            ->assertNotFound();

        // User B tries to delete User A's notification
        $this->actingAs($userB)
            ->deleteJson("/api/v1/notifications/{$notificationA->id}")
            ->assertNotFound();

        $this->assertNull($notificationA->fresh()->read_at);

        // User A successfully reads and deletes own notification
        $this->actingAs($userA)
            ->patchJson("/api/v1/notifications/{$notificationA->id}/read")
            ->assertOk();
        $this->assertNotNull($notificationA->fresh()->read_at);

        $this->actingAs($userA)
            ->deleteJson("/api/v1/notifications/{$notificationA->id}")
            ->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $notificationA->id]);
    }

    /** 13. Notification processing behaves correctly with queued notifications. */
    public function test_notification_processing_behaves_correctly_with_queued_notifications(): void
    {
        $customer = $this->createCustomer();
        $ticket = $this->createTicket($customer);

        $event = OutboxEvent::query()->where('ticket_id', $ticket->id)->sole();
        $job = new DeliverOutboxEvent($event->id);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(config('support.notifications_queue'), $job->queue);

        $notification = new TicketCreatedNotification($ticket->id, 'ticket.created', $event->id);
        $this->assertInstanceOf(ShouldQueue::class, $notification);

        // Execute job directly to ensure end-to-end delivery
        $job->handle(app(DispatchOutboxEvent::class));
        $this->assertNotNull($event->fresh()->dispatched_at);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $customer->id,
            'notifiable_type' => User::class,
        ]);
    }

    /** 14. Repeated SLA scheduler execution does not generate duplicate threshold alerts. */
    public function test_repeated_sla_scheduler_execution_does_not_generate_duplicate_threshold_alerts(): void
    {
        Notification::fake();
        $customer = $this->createCustomer();
        $agent = $this->createAgent();
        $admin = $this->createAdmin();

        $ticket = $this->createTicket($customer);
        app(AssignTicket::class)->execute($ticket->id, $admin, $agent->id);

        $ticket->update([
            'status' => TicketStatus::InProgress,
            'sla_warning_at' => now()->subMinutes(10),
            'sla_due_at' => now()->addMinutes(20),
        ]);

        $slaService = app(SlaEvaluationService::class);
        $slaService->execute();
        $slaService->execute(); // Second run

        $this->dispatchAllOutbox();

        // Exactly one warning escalation record and notification
        $this->assertSame(1, $ticket->escalations()->where('cycle', 1)->where('level', 'warning')->count());
        Notification::assertSentToTimes($agent, SlaApproachingNotification::class, 1);
    }
}

