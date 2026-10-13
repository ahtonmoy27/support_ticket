<?php

namespace Tests\Feature;

use App\Domain\Tickets\Actions\CreateTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SupportSeeder;
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

    /** @param list<array> $arguments */
    private function concurrent(array $arguments): array
    {
        $processes = [];
        $results = [];
        $start = microtime(true) + 1;
        foreach ($arguments as $args) {
            $command = [PHP_BINARY, base_path('tests/Support/claim-worker.php'), $args[0], (string) $args[1], (string) $start, (string) $args[2]];
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
            $results[] = trim($output);
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
}
