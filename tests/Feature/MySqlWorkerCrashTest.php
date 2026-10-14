<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CrashOnceImport;
use Tests\TestCase;

class MySqlWorkerCrashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires isolated MySQL database and subprocess worker.');
        }
        if (config('database.connections.mysql.database') !== 'support_ticket_sla_test') {
            $this->fail('Worker crash test requires support_ticket_sla_test.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null,
            'queue.default' => 'database', 'queue.connections.database.connection' => 'mysql']);
        $this->seed(SupportSeeder::class);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function worker(): Process
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'database', '--queue=imports',
            '--stop-when-empty', '--sleep=0', '--max-time=10', '--no-interaction'], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'support_ticket_sla_test',
                'DB_URL' => '', 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database', 'MAIL_MAILER' => 'array',
            ]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    public function test_worker_process_crash_preserves_rows_and_retry_resumes_without_duplicates(): void
    {
        $customer = User::factory()->create();
        $category = Category::first();
        $path = 'imports/test-crash-'.Str::uuid().'.csv';
        $import = TicketImport::factory()->create(['storage_key' => $path]);
        Storage::disk('private')->put($path, "customer_email,category,subject,description,priority\n{$customer->email},{$category->name},First,Description,critical\n{$customer->email},{$category->name},Second,Description,high\n");
        try {
            dispatch(new CrashOnceImport($import->id));
            $crashed = $this->worker();
            $this->assertSame(17, $crashed->getExitCode(), $crashed->getErrorOutput().$crashed->getOutput());
            $this->assertSame('processing', $import->refresh()->status);
            $this->assertSame(1, Ticket::count());
            $this->assertSame(1, DB::table('jobs')->where('queue', 'imports')->count());
            $this->assertGreaterThan(0, DB::table('cache_locks')->count());
            DB::table('jobs')->where('queue', 'imports')->update(['reserved_at' => now()->timestamp - 1201]);
            DB::table('cache_locks')->update(['expiration' => now()->timestamp - 1]);
            $resumed = $this->worker();
            $this->assertSame(0, $resumed->getExitCode(), $resumed->getErrorOutput().$resumed->getOutput());
            $this->assertSame('completed', $import->refresh()->status);
            $this->assertSame(2, $import->processed_rows);
            $this->assertSame(2, Ticket::count());
            $this->assertSame(0, DB::table('jobs')->where('queue', 'imports')->count());
            $this->assertSame(0, DB::table('cache_locks')->count());
        } finally {
            Storage::disk('private')->delete($path);
        }
    }
}
