<?php

namespace Tests\Feature;

use App\Domain\Auth\Services\AuthenticationCodeService;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Services\SupportHealthCheck;
use App\Jobs\ProcessTicketImport;
use App\Models\Category;
use App\Models\OutboxEvent;
use App\Models\TicketImport;
use App\Models\User;
use App\Notifications\AuthenticationCodeNotification;
use App\Notifications\TicketChanged;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null,
            'queue.default' => 'database', 'queue.connections.database.connection' => config('database.default')]);
        $this->seed(SupportSeeder::class);
        Storage::fake('private');
    }

    private function work(string $queue): void
    {
        app('queue.worker')->shouldQuit = false;
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
            '--sleep' => 0, '--max-time' => 30, '--no-interaction' => true])->assertSuccessful();
    }

    private function csv(): string
    {
        $customer = User::factory()->create();
        $category = Category::first();

        return "customer_email,category,subject,description,priority\n{$customer->email},{$category->name},Queued,Description,critical\n";
    }

    public function test_authentication_mail_is_encrypted_in_real_queue_and_processed_by_worker(): void
    {
        $user = User::factory()->unverified()->create();
        $code = app(AuthenticationCodeService::class)->issue($user->email, 'registration');
        $user->notify((new AuthenticationCodeNotification($code, 'registration'))->beforeCommit());
        $payload = DB::table('jobs')->where('queue', 'notifications')->value('payload');
        $this->assertNotNull($payload);
        $this->assertStringNotContainsString($code, $payload);
        $this->work('notifications');
        $this->assertSame(0, Queue::size('notifications'));
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_real_worker_processes_duplicate_import_jobs_without_duplicate_tickets(): void
    {
        $import = TicketImport::factory()->create();
        Storage::disk('private')->put($import->storage_key, $this->csv());
        ProcessTicketImport::dispatch($import->id);
        ProcessTicketImport::dispatch($import->id);
        $this->work('imports');
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame(0, Queue::size('imports'));
    }

    public function test_transient_read_failure_is_released_and_real_worker_retries(): void
    {
        $import = TicketImport::factory()->create();
        ProcessTicketImport::dispatch($import->id);
        $this->work('imports');
        $this->assertSame(1, Queue::size('imports'));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'imports')->value('attempts'));
        Storage::disk('private')->put($import->storage_key, $this->csv());
        DB::table('jobs')->where('queue', 'imports')->update(['available_at' => now()->timestamp]);
        $this->work('imports');
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_exhausted_import_can_be_explicitly_retried_and_resumed(): void
    {
        $import = TicketImport::factory()->create();
        $job = new ProcessTicketImport($import->id);
        $job->tries = 1;
        dispatch($job);
        $this->work('imports');
        $this->assertSame('failed', $import->refresh()->status);
        $this->assertDatabaseCount('failed_jobs', 1);
        $health = app(SupportHealthCheck::class)->execute();
        $this->assertSame(1, $health['metrics']['failed_jobs']);
        $this->assertSame(1, $health['metrics']['failed_imports']);
        Storage::disk('private')->put($import->storage_key, $this->csv());
        $this->artisan('tickets:retry-import', ['import' => $import->id])->assertSuccessful();
        $this->work('imports');
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_expired_reservation_from_abandoned_worker_is_redelivered(): void
    {
        $import = TicketImport::factory()->create(['status' => 'processing']);
        Storage::disk('private')->put($import->storage_key, $this->csv());
        ProcessTicketImport::dispatch($import->id);
        $reserved = Queue::connection()->pop('imports');
        $this->assertNotNull($reserved);
        DB::table('jobs')->where('id', $reserved->getJobId())->update(['reserved_at' => now()->timestamp - 1201]);
        $this->work('imports');
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_duplicate_notification_jobs_create_one_database_notification(): void
    {
        $customer = User::factory()->create();
        $ticket = app(CreateTicket::class)->execute($customer, [
            'subject' => 'Notification', 'description' => 'Queued notification', 'priority' => 'critical', 'category_id' => Category::first()->id,
        ]);
        $event = OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => 'ticket.updated', 'recipient_ids' => [$customer->id]]);
        $customer->notify(new TicketChanged($ticket->id, 'ticket.updated', $event->id));
        $customer->notify(new TicketChanged($ticket->id, 'ticket.updated', $event->id));
        $this->work('notifications');
        $this->assertSame(1, $customer->notifications()->count());
        $this->assertSame(0, Queue::size('notifications'));
    }

    public function test_outbox_queue_failure_rolls_back_queued_jobs_and_can_be_dispatched_again(): void
    {
        $customer = User::factory()->create();
        $ticket = app(CreateTicket::class)->execute($customer, [
            'subject' => 'Outbox', 'description' => 'Atomic queue dispatch', 'priority' => 'critical', 'category_id' => Category::first()->id,
        ]);
        $event = OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => 'ticket.updated', 'recipient_ids' => [$customer->id]]);
        Event::listen(JobQueued::class, fn () => throw new \RuntimeException('Simulated queue dispatch outage.'));
        try {
            $this->artisan('tickets:dispatch-outbox');
            $this->fail('Expected dispatch failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated queue dispatch outage.', $exception->getMessage());
        } finally {
            Event::forget(JobQueued::class);
        }
        $this->assertNull($event->refresh()->dispatched_at);
        $this->assertSame(0, Queue::size('notifications'));
        $this->artisan('tickets:dispatch-outbox')->assertSuccessful();
        $this->work('notifications');
        $this->assertNotNull($event->refresh()->dispatched_at);
        $this->assertSame(2, $customer->notifications()->count());
        $this->assertEqualsCanonicalizing(['ticket.created', 'ticket.updated'], $customer->notifications()->get()->pluck('data.event')->all());
    }

    public function test_worker_rechecks_recipient_access_before_delivering_notification(): void
    {
        $customer = User::factory()->create();
        $ticket = app(CreateTicket::class)->execute($customer, [
            'subject' => 'Disabled recipient', 'description' => 'Queued authorization check', 'priority' => 'critical', 'category_id' => Category::first()->id,
        ]);
        $event = OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => 'ticket.updated', 'recipient_ids' => [$customer->id]]);
        $customer->notify(new TicketChanged($ticket->id, 'ticket.updated', $event->id));
        $customer->update(['is_active' => false]);
        $this->work('notifications');
        $this->assertSame(0, $customer->notifications()->count());
    }
}
