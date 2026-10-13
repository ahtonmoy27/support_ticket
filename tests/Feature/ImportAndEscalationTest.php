<?php

namespace Tests\Feature;

use App\Domain\SLA\Services\SlaEvaluationService;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Jobs\ProcessTicketImport;
use App\Models\Category;
use App\Models\OutboxEvent;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use App\Notifications\Channels\DatabaseChannel;
use App\Notifications\TicketChanged;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportAndEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
        Storage::fake('private');
    }

    private function ticket(): Ticket
    {
        return app(CreateTicket::class)->execute(User::factory()->create(), ['subject' => 'Example', 'description' => 'Example description', 'category_id' => Category::first()->id, 'priority' => 'critical']);
    }

    private function import(string $contents): TicketImport
    {
        Storage::disk('private')->put('imports/tickets.csv', $contents);

        return TicketImport::factory()->create(['storage_key' => 'imports/tickets.csv']);
    }

    public function test_warnings_breaches_and_outbox_are_idempotent(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['sla_warning_at' => now()->subMinutes(1), 'sla_due_at' => now()->addMinutes(10)]);
        $service = app(SlaEvaluationService::class);
        $this->assertSame(1, $service->execute());
        $this->assertSame(0, $service->execute());
        $ticket->update(['sla_due_at' => now()->subMinute()]);
        $this->assertSame(1, $service->execute());
        $this->assertSame(0, $service->execute());
        $this->assertSame(2, $ticket->escalations()->count());
        $this->assertTrue($ticket->refresh()->sla_breached_at->equalTo($ticket->sla_due_at));
        $this->assertSame(2, $ticket->activities()->where('action', 'like', 'sla.%')->count());
    }

    public function test_delayed_scan_emits_only_breach_and_ignores_resolved_tickets(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['sla_warning_at' => now()->subHour(), 'sla_due_at' => now()->subMinute()]);
        $resolved = $this->ticket();
        $resolved->update(['status' => 'resolved', 'resolved_at' => now(), 'sla_warning_at' => now()->subHour(), 'sla_due_at' => now()->subMinute()]);
        $this->artisan('tickets:check-sla')->assertSuccessful();
        $this->assertSame(['breach'], $ticket->escalations()->pluck('level')->all());
        $this->assertSame(0, $resolved->escalations()->count());
    }

    public function test_outbox_dispatches_once_and_notification_omits_private_content(): void
    {
        Notification::fake();
        $ticket = $this->ticket();
        $agent = User::factory()->create(['role' => 'agent']);
        $ticket->update(['assigned_agent_id' => $agent->id]);
        $event = OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => 'ticket.assigned', 'recipient_ids' => [$agent->id]]);
        $this->artisan('tickets:dispatch-outbox')->assertSuccessful();
        $this->artisan('tickets:dispatch-outbox')->assertSuccessful();
        Notification::assertSentToTimes($agent, TicketChanged::class, 1);
        $this->assertNotNull($event->refresh()->dispatched_at);
        $notification = new TicketChanged($ticket->id, 'ticket.replied', $event->id);
        $this->assertArrayNotHasKey('body', $notification->toArray($agent));
        $ticket->update(['assigned_agent_id' => null]);
        $this->assertFalse($notification->shouldSend($agent, 'mail'));
    }

    public function test_import_streams_valid_invalid_and_duplicate_rows_without_retry_duplicates(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.com']);
        $category = Category::first()->name;
        $import = $this->import("customer_email,category,subject,description,priority,source_system,external_ticket_id\ncustomer@example.com,$category,One,Description,critical,legacy,123\ncustomer@example.com,$category,Duplicate,Description,critical,legacy,123\ncustomer@example.com,$category,Bad,Description,invalid,,\nmissing@example.com,$category,Missing,Description,high,,\ncustomer@example.com,$category,Two,Description,high,,\n");
        $job = new ProcessTicketImport($import->id);
        $job->handle(app(CreateTicket::class));
        $import->refresh();
        $this->assertSame('completed', $import->status);
        $this->assertSame(5, $import->processed_rows);
        $this->assertSame(2, $import->failed_rows);
        $this->assertSame(1, $import->skipped_rows);
        $this->assertSame(2, Ticket::count());
        $job->handle(app(CreateTicket::class));
        $this->assertSame(2, Ticket::count());
        $import->update(['status' => 'processing']);
        $job->handle(app(CreateTicket::class));
        $this->assertSame(2, Ticket::count());
        $this->assertSame(5, $import->refresh()->processed_rows);
        $this->actingAs($import->creator)->get('/api/v1/admin/imports/'.$import->id.'/errors')->assertOk()->assertDownload('import-'.$import->id.'-errors.csv');
    }

    public function test_bad_headers_and_row_limits_fail_cleanly(): void
    {
        $import = $this->import("wrong,headers\n1,2\n");
        (new ProcessTicketImport($import->id))->handle(app(CreateTicket::class));
        $this->assertSame('failed', $import->refresh()->status);
        $this->assertNotNull($import->error);
        User::factory()->create(['email' => 'customer@example.com']);
        $category = Category::first()->name;
        $import = $this->import("customer_email,category,subject,description,priority\ncustomer@example.com,$category,One,Description,critical\ncustomer@example.com,$category,Two,Description,critical\n");
        config(['support.import_max_rows' => 1]);
        (new ProcessTicketImport($import->id))->handle(app(CreateTicket::class));
        $this->assertSame('failed', $import->refresh()->status);
        $this->assertSame(1, Ticket::count());
    }

    public function test_import_endpoint_is_admin_only_and_queues_private_upload(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/admin/imports', [])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->postJson('/api/v1/admin/imports', ['file' => UploadedFile::fake()->createWithContent('tickets.csv', "customer_email,category,subject,description,priority\n")])->assertStatus(202)->assertJsonMissingPath('data.storage_key');
        Queue::assertPushed(ProcessTicketImport::class);
        Storage::disk('private')->assertExists(TicketImport::find($response->json('data.id'))->storage_key);
    }

    public function test_database_notification_delivery_is_idempotent(): void
    {
        $ticket = $this->ticket();
        $event = OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => 'ticket.created', 'recipient_ids' => [$ticket->customer_id]]);
        $notification = new TicketChanged($ticket->id, 'ticket.created', $event->id);
        $channel = new DatabaseChannel;
        $channel->send($ticket->customer, $notification);
        $channel->send($ticket->customer, $notification);
        $this->assertSame(1, $ticket->customer->notifications()->count());
    }
}
