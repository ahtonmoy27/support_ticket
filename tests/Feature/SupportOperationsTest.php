<?php

namespace Tests\Feature;

use App\Domain\Assignment\Actions\AssignTicket;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Actions\UploadTicketAttachment;
use App\Domain\Tickets\Services\SupportHealthCheck;
use App\Jobs\ProcessTicketImport;
use App\Models\Category;
use App\Models\OutboxEvent;
use App\Models\TicketImport;
use App\Models\User;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupportOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
    }

    public function test_recovery_requeues_stale_processing_and_pending_imports_once(): void
    {
        Queue::fake();
        foreach (['processing', 'pending'] as $status) {
            TicketImport::factory()->create(['status' => $status, 'updated_at' => now()->subHour()]);
        }
        TicketImport::factory()->create(['status' => 'processing']);
        TicketImport::factory()->create(['status' => 'completed', 'updated_at' => now()->subHour()]);
        $this->artisan('tickets:recover-imports')->assertSuccessful();
        $this->artisan('tickets:recover-imports')->assertSuccessful();
        Queue::assertPushed(ProcessTicketImport::class, 2);
        $this->assertDatabaseCount('ticket_imports', 4);
    }

    public function test_retry_only_accepts_failed_imports_and_keeps_existing_rows(): void
    {
        Queue::fake();
        $import = TicketImport::factory()->create(['status' => 'failed', 'error' => 'Transient failure', 'processed_rows' => 1]);
        $import->rows()->create(['row_number' => 1, 'status' => 'failed', 'errors' => ['row' => ['Invalid row']]]);
        $this->artisan('tickets:retry-import', ['import' => $import->id])->assertSuccessful();
        $this->artisan('tickets:retry-import', ['import' => $import->id])->assertFailed();
        $this->assertSame('pending', $import->refresh()->status);
        $this->assertSame(1, $import->rows()->count());
        Queue::assertPushed(ProcessTicketImport::class, 1);
    }

    public function test_monitor_alerts_for_stale_operations_and_deduplicates_logs(): void
    {
        Log::spy();
        $ticket = app(CreateTicket::class)->execute(User::factory()->create(), [
            'subject' => 'Monitor', 'description' => 'Monitor test', 'priority' => 'critical', 'category_id' => Category::first()->id,
        ]);
        OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => 'ticket.created', 'recipient_ids' => [], 'created_at' => now()->subHour()]);
        TicketImport::factory()->create(['status' => 'processing', 'updated_at' => now()->subHour()]);
        $this->artisan('tickets:monitor', ['--json' => true])->assertFailed();
        $this->artisan('tickets:monitor', ['--json' => true])->assertFailed();
        Log::shouldHaveReceived('warning')->once();
        $report = app(SupportHealthCheck::class)->execute();
        $this->assertContains('stale_outbox', $report['issues']);
        $this->assertContains('stuck_imports', $report['issues']);
        $this->assertContains('sla_scheduler_stale', $report['issues']);
    }

    public function test_successful_commands_publish_heartbeats_and_health_is_admin_only(): void
    {
        $this->artisan('tickets:check-sla')->assertSuccessful();
        $this->artisan('tickets:dispatch-outbox')->assertSuccessful();
        $this->assertNotNull(Cache::get('support.heartbeat.sla'));
        $this->artisan('tickets:monitor', ['--json' => true])->assertSuccessful();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/health')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/admin/health')
            ->assertOk()->assertJsonPath('data.healthy', true)->assertJsonStructure(['data' => ['metrics', 'issues', 'checked_at']]);
    }

    public function test_assignment_rejects_agent_disabled_after_ticket_creation(): void
    {
        $category = Category::first();
        $agent = User::factory()->create(['role' => 'agent']);
        $agent->categories()->attach($category);
        $ticket = app(CreateTicket::class)->execute(User::factory()->create(), [
            'subject' => 'Eligibility', 'description' => 'Eligibility test', 'priority' => 'critical', 'category_id' => $category->id,
        ]);
        $agent->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(AssignTicket::class)->execute($ticket->id, User::factory()->create(['role' => 'admin']), $agent->id);
    }

    public function test_storage_write_failure_does_not_create_attachment_or_audit(): void
    {
        $customer = User::factory()->create();
        $ticket = app(CreateTicket::class)->execute($customer, [
            'subject' => 'Storage', 'description' => 'Storage test', 'priority' => 'critical', 'category_id' => Category::first()->id,
        ]);
        $file = \Mockery::mock(UploadedFile::class);
        $file->shouldReceive('store')->once()->andReturn(false);
        try {
            app(UploadTicketAttachment::class)->execute($ticket->id, $customer, $file);
            $this->fail('Expected storage failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot store attachment.', $exception->getMessage());
        }
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame(0, $ticket->activities()->where('action', 'ticket.attachment_added')->count());
    }

    public function test_monitor_detects_old_jobs_even_when_queue_is_small(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default')]);
        $import = TicketImport::factory()->create();
        ProcessTicketImport::dispatch($import->id);
        DB::table('jobs')->where('queue', 'imports')->update(['created_at' => now()->timestamp - 600, 'available_at' => now()->timestamp - 600]);
        $report = app(SupportHealthCheck::class)->execute();
        $this->assertSame(1, $report['metrics']['imports_queued_jobs']);
        $this->assertGreaterThanOrEqual(600, $report['metrics']['imports_oldest_job_age_seconds']);
        $this->assertContains('imports_queue_backlog', $report['issues']);
    }

    public function test_late_failure_from_duplicate_job_does_not_overwrite_completed_import(): void
    {
        $import = TicketImport::factory()->create(['status' => 'completed']);
        (new ProcessTicketImport($import->id))->failed(new \RuntimeException('Late duplicate failure'));
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertNull($import->error);
    }
}
