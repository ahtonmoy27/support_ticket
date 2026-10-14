<?php

namespace Tests\Feature;

use App\Domain\Tickets\Actions\CreateTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySqlAssignmentConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires isolated MySQL 8.4 database.');
        }
        if (config('database.connections.mysql.database') !== 'support_ticket_sla_test') {
            $this->fail('Concurrency tests require support_ticket_sla_test, never an application database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /**
     * @param  list<array{0:string,1:int,2:int,3?:int}>  $arguments
     * @return list<string|array{status:int,duration_ms:float|int,queries:int,query_ms:float|int,transaction_rollbacks:int}>
     */
    private function concurrent(array $arguments, bool $metrics = false): array
    {
        $processes = [];
        $results = [];
        $start = microtime(true) + 1;
        foreach ($arguments as $args) {
            $command = [PHP_BINARY, base_path('tests/Support/claim-worker.php'), $args[0], (string) $args[1], (string) $start, (string) $args[2]];
            if ($metrics || isset($args[3])) {
                $command[] = $metrics ? 'metrics' : '';
                $command[] = (string) ($args[3] ?? 0);
            }
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process,$pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $results[] = $metrics ? json_decode($output, true, flags: JSON_THROW_ON_ERROR) : trim($output);
        }

        return $results;
    }

    public function test_two_processes_claiming_one_ticket_have_exactly_one_winner(): void
    {
        $category = Category::first();
        $customer = User::factory()->create();
        $one = User::factory()->create(['role' => 'agent']);
        $two = User::factory()->create(['role' => 'agent']);
        $one->categories()->attach($category);
        $two->categories()->attach($category);
        $ticket = app(CreateTicket::class)->execute($customer, ['subject' => 'Race', 'description' => 'Race condition test', 'priority' => 'critical', 'category_id' => $category->id]);
        $results = $this->concurrent([['claim', $one->id, $ticket->id], ['claim', $two->id, $ticket->id]]);
        sort($results);
        $this->assertSame(['200', '409'], $results);
        $this->assertContains($ticket->refresh()->assigned_agent_id, [$one->id, $two->id]);
        $this->assertSame(1, $ticket->activities()->where('action', 'ticket.claimed')->count());
    }

    public function test_parallel_creation_balances_workload_and_preserves_unique_numbers(): void
    {
        config(['support.auto_assign' => true]);
        $category = Category::first();
        $customer = User::factory()->create();
        $one = User::factory()->create(['role' => 'agent']);
        $two = User::factory()->create(['role' => 'agent']);
        $one->categories()->attach($category);
        $two->categories()->attach($category);
        $results = $this->concurrent(array_fill(0, 4, ['create', $customer->id, $category->id]));
        $this->assertSame(['200', '200', '200', '200'], $results);
        $this->assertSame(4, Ticket::count());
        $this->assertSame(4, Ticket::distinct()->count('ticket_number'));
        $this->assertSame(2, $one->assignedTickets()->count());
        $this->assertSame(2, $two->assignedTickets()->count());
    }

    public function test_unrelated_category_is_not_blocked_by_another_category_or_legacy_mutex(): void
    {
        $category = Category::first();
        $otherCategory = Category::factory()->create();
        $customer = User::factory()->create();
        DB::transaction(function () use ($category, $otherCategory, $customer): void {
            Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            DB::table('support_locks')->where('name', 'assignment')->lockForUpdate()->first();
            $this->assertSame(['200'], $this->concurrent([['create', $customer->id, $otherCategory->id]]));
        });
    }

    public function test_agents_shared_across_categories_are_balanced_under_parallel_creation(): void
    {
        $categories = [Category::first(), Category::factory()->create()];
        $customer = User::factory()->create();
        $agents = User::factory()->count(2)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            $agent->categories()->attach(array_map(fn (Category $category): int => $category->id, $categories));
        }
        $results = $this->concurrent([
            ['create', $customer->id, $categories[0]->id], ['create', $customer->id, $categories[1]->id],
            ['create', $customer->id, $categories[0]->id], ['create', $customer->id, $categories[1]->id],
        ]);
        $this->assertSame(['200', '200', '200', '200'], $results);
        foreach ($agents as $agent) {
            $this->assertSame(2, $agent->assignedTickets()->count());
        }
    }

    public function test_parallel_imports_with_same_external_id_create_one_ticket(): void
    {
        $customer = User::factory()->create();
        $imports = TicketImport::factory()->count(4)->create();
        $arguments = $imports->map(fn (TicketImport $import): array => ['import-duplicate', $customer->id, $import->id])->all();
        $this->assertSame(['200', '200', '200', '200'], $this->concurrent($arguments));
        $this->assertSame(1, Ticket::count());
        $this->assertSame(3, (int) TicketImport::sum('skipped_rows'));
        $this->assertSame(4, (int) TicketImport::sum('processed_rows'));
    }

    public function test_parallel_reassignment_preserves_a_complete_audit_chain(): void
    {
        $category = Category::first();
        $admin = User::factory()->create(['role' => 'admin']);
        $agents = User::factory()->count(2)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            $agent->categories()->attach($category);
        }
        $ticket = app(CreateTicket::class)->execute(User::factory()->create(), [
            'subject' => 'Reassign', 'description' => 'Assignment race', 'priority' => 'critical', 'category_id' => $category->id,
        ]);
        $this->assertSame(['200', '200'], $this->concurrent([
            ['assign', $admin->id, $ticket->id, $agents[0]->id], ['assign', $admin->id, $ticket->id, $agents[1]->id],
        ]));
        $activities = $ticket->activities()->where('action', 'ticket.assigned')->orderBy('id')->get();
        $this->assertCount(2, $activities);
        $this->assertSame($activities[0]->new_values['assigned_agent_id'], $activities[1]->old_values['assigned_agent_id']);
        $this->assertSame($activities[1]->new_values['assigned_agent_id'], $ticket->refresh()->assigned_agent_id);
    }

    public function test_load_profile_measures_concurrent_create_claim_assign_and_import(): void
    {
        $category = Category::first();
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $agents = User::factory()->count(2)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            $agent->categories()->attach($category);
        }
        $profiles = [];
        foreach (['create', 'claim', 'assign', 'import'] as $operation) {
            $arguments = [];
            for ($index = 0; $index < 8; $index++) {
                if ($operation === 'create') {
                    $arguments[] = ['create', $customer->id, $category->id];
                } elseif ($operation === 'import') {
                    $arguments[] = ['import', $customer->id, TicketImport::factory()->create()->id];
                } else {
                    $ticket = app(CreateTicket::class)->execute($customer, [
                        'subject' => 'Load', 'description' => 'Load fixture', 'priority' => 'critical', 'category_id' => $category->id,
                    ]);
                    $arguments[] = $operation === 'claim' ? ['claim', $agents[0]->id, $ticket->id]
                        : ['assign', $admin->id, $ticket->id, $agents[1]->id];
                }
            }
            $samples = [];
            $started = hrtime(true);
            foreach (array_chunk($arguments, 4) as $batch) {
                $samples = array_merge($samples, $this->concurrent($batch, true));
            }
            $seconds = (hrtime(true) - $started) / 1e9;
            $latencies = array_column($samples, 'duration_ms');
            sort($latencies);
            $profiles[$operation] = [
                'requests' => count($samples), 'concurrency' => 4,
                'p50_ms' => $latencies[(int) ceil(count($latencies) * .50) - 1],
                'p95_ms' => $latencies[(int) ceil(count($latencies) * .95) - 1],
                'throughput_per_second_including_process_startup' => round(count($samples) / $seconds, 2),
                'max_queries' => max(array_column($samples, 'queries')),
                'transaction_rollbacks' => array_sum(array_column($samples, 'transaction_rollbacks')),
                'failed_requests' => count(array_filter($samples, fn (array $sample): bool => $sample['status'] !== 200)),
            ];
        }
        $path = storage_path('framework/testing/support-load.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode(['measured_at' => now()->toIso8601String(), 'scope' => 'local MySQL domain actions; excludes HTTP and production traffic', 'profiles' => $profiles], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        foreach ($profiles as $operation => $profile) {
            $this->assertSame(0, $profile['failed_requests'], $operation);
            $this->assertLessThan((int) (getenv('SUPPORT_LOAD_MAX_P95_MS') ?: 5000), $profile['p95_ms'], $operation);
            $this->assertLessThanOrEqual(80, $profile['max_queries'], $operation);
        }
        $this->assertSame(32, Ticket::count());
    }
}
