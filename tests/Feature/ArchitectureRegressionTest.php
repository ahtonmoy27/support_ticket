<?php

namespace Tests\Feature;

use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Actions\ImportTicketRow;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Domain\Tickets\Services\TicketImportProcessor;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchitectureRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
        Storage::fake('private');
    }

    private function ticket(User $customer): Ticket
    {
        return app(CreateTicket::class)->execute($customer, [
            'subject' => 'Regression', 'description' => 'Regression test',
            'category_id' => Category::first()->id, 'priority' => 'critical',
        ]);
    }

    public function test_assignment_query_count_does_not_grow_with_agent_count(): void
    {
        config(['support.auto_assign' => true]);
        $category = Category::first();
        $customer = User::factory()->create();
        $agent = User::factory()->create(['role' => 'agent']);
        $agent->categories()->attach($category);
        $this->ticket($customer);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->ticket($customer);
            $oneAgentQueries = count(DB::getQueryLog());
            DB::disableQueryLog();
            foreach (User::factory()->count(15)->create(['role' => 'agent']) as $additionalAgent) {
                $additionalAgent->categories()->attach($category);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ticket($customer);
            $this->assertSame($oneAgentQueries, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_date_filter_accepts_end_date_without_start_date(): void
    {
        $user = User::factory()->create();
        $this->ticket($user);
        $this->actingAs($user)->getJson('/api/v1/tickets?to='.now()->toDateString())
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tickets?from=2026-10-15&to=2026-10-14')->assertUnprocessable();
    }

    public function test_activity_actor_summary_and_ticket_calendar_are_exposed_without_credentials(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user);
        $this->actingAs($user)->getJson('/api/v1/tickets/'.$ticket->id.'/activities')
            ->assertOk()->assertJsonPath('data.0.actor.name', $user->name)
            ->assertJsonMissingPath('data.0.actor.email')->assertJsonMissingPath('data.0.actor.password');
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()->assertJsonPath('data.sla_timezone', 'Asia/Dhaka');
    }

    public function test_assignment_ignores_finished_work_and_ineligible_agents(): void
    {
        $category = Category::first();
        $customer = User::factory()->create();
        $first = User::factory()->create(['role' => 'agent']);
        $second = User::factory()->create(['role' => 'agent']);
        $unavailable = User::factory()->create(['role' => 'agent', 'is_available' => false]);
        foreach ([$first, $second, $unavailable] as $agent) {
            $agent->categories()->attach($category);
        }
        $this->ticket($customer)->update(['assigned_agent_id' => $first->id, 'status' => TicketStatus::Resolved]);
        $this->ticket($customer)->update(['assigned_agent_id' => $second->id, 'status' => TicketStatus::InProgress]);
        config(['support.auto_assign' => true]);
        $this->assertSame($first->id, $this->ticket($customer)->assigned_agent_id);
    }

    public function test_api_lists_share_pagination_and_hide_private_storage_and_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $agent->categories()->attach(Category::first());
        TicketImport::factory()->create(['created_by' => $admin->id, 'storage_key' => 'private/secret.csv']);
        $ticket = $this->ticket(User::factory()->create());
        $this->actingAs($admin);
        foreach (['admin/agents', 'admin/customers', 'admin/sla-policies', 'admin/imports', 'notifications',
            'tickets/'.$ticket->id.'/messages', 'tickets/'.$ticket->id.'/attachments', 'tickets/'.$ticket->id.'/activities'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk()->assertJsonStructure([
                'data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total'],
            ]);
        }
        $this->getJson('/api/v1/admin/agents')->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonPath('data.0.categories.0.id', Category::first()->id);
        $this->getJson('/api/v1/admin/imports')->assertJsonMissingPath('data.0.storage_key');
        $this->getJson('/api/v1/admin/sla-policies')->assertJsonStructure(['data' => [['calendar' => ['hours', 'timezone'], 'rules' => [['priority', 'resolution_minutes']]]]]);
    }

    public function test_failed_attachment_write_to_closed_ticket_cleans_up_file(): void
    {
        $customer = User::factory()->create();
        $ticket = $this->ticket($customer);
        $ticket->update(['status' => TicketStatus::Closed]);
        $this->actingAs($customer)->postJson('/api/v1/tickets/'.$ticket->id.'/attachments', [
            'file' => UploadedFile::fake()->createWithContent('readme.txt', 'test'),
        ])->assertConflict();
        $this->assertSame([], Storage::disk('private')->allFiles('attachments'));
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_category_update_can_keep_its_name_and_still_enforces_uniqueness(): void
    {
        $category = Category::first();
        $other = Category::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/v1/admin/categories/'.$category->id, ['name' => $category->name, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson('/api/v1/admin/categories/'.$category->id, ['name' => $other->name])->assertUnprocessable();
    }

    public function test_import_with_bom_blank_lines_and_malformed_row_continues_and_can_resume(): void
    {
        $customer = User::factory()->create(['email' => 'import@example.com']);
        $category = Category::first();
        $import = TicketImport::factory()->create(['storage_key' => 'imports/regression.csv']);
        Storage::disk('private')->put($import->storage_key,
            "\xEF\xBB\xBFcustomer_email,category,subject,description,priority\n\nimport@example.com,{$category->name},One,Description,critical\nmissing,column\nimport@example.com,{$category->name},Three,Description,high\n");
        app(TicketImportProcessor::class)->execute($import->id);
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(1, $import->failed_rows);
        $this->assertSame(2, Ticket::where('customer_id', $customer->id)->count());
        $import->update(['status' => 'processing']);
        app(TicketImportProcessor::class)->execute($import->id);
        $this->assertSame(3, $import->refresh()->processed_rows);
        $this->assertDatabaseCount('tickets', 2);
    }

    public function test_imports_deduplicate_external_id_zero_across_files(): void
    {
        $customer = User::factory()->create();
        $category = Category::first();
        $row = [
            'customer_email' => $customer->email, 'category' => $category->name,
            'subject' => 'Zero identifier', 'description' => 'Legacy import', 'priority' => 'critical',
            'source_system' => '0', 'external_ticket_id' => '0',
        ];
        $first = TicketImport::factory()->create();
        $second = TicketImport::factory()->create();
        app(ImportTicketRow::class)->execute($first->id, 1, $row);
        app(ImportTicketRow::class)->execute($second->id, 1, $row);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame(1, $second->refresh()->skipped_rows);
        $this->assertSame('skipped', $second->rows()->first()->status);
    }
}
