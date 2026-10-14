<?php

namespace Tests\Feature;

use App\Domain\SLA\Services\SlaEvaluationService;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Services\TicketImportProcessor;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Database\Seeders\SupportSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AuditRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
    }

    private function ticket(User $customer, string $subject = 'Regression'): Ticket
    {
        return app(CreateTicket::class)->execute($customer, [
            'subject' => $subject, 'description' => 'Description',
            'category_id' => Category::first()->id, 'priority' => 'critical',
        ]);
    }

    public function test_seeding_without_credentials_creates_no_administrator(): void
    {
        $this->assertDatabaseCount('users', 0);
        config(['support.admin_email' => '', 'support.admin_password' => '']);
        $this->seed(SupportSeeder::class);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('categories', 3);
    }

    public function test_bootstrap_never_prints_password_or_overwrites_existing_credentials(): void
    {
        $password = 'PrivateBootstrapPassword!';
        config(['support.admin_email' => 'bootstrap@example.test', 'support.admin_password' => $password]);
        $output = new BufferedOutput;
        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, app(Kernel::class)->call('db:seed', [
            '--class' => SupportSeeder::class, '--no-interaction' => true,
        ], $output));
        $this->assertStringNotContainsString($password, $output->fetch());
        $admin = User::where('email', 'bootstrap@example.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check($password, $admin->password));
        config(['support.admin_password' => 'AnotherPrivatePassword!']);
        $this->seed(SupportSeeder::class);
        $this->assertTrue(Hash::check($password, $admin->refresh()->password));
    }

    public function test_partial_bootstrap_credentials_fail_closed(): void
    {
        config(['support.admin_email' => 'bootstrap@example.test']);
        $this->expectException(\InvalidArgumentException::class);
        $this->seed(SupportSeeder::class);
    }

    public function test_inactive_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->getJson('/api/v1/me')->assertForbidden();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_long_unicode_csv_description_is_imported_as_one_record(): void
    {
        Storage::fake('private');
        $customer = User::factory()->create();
        $category = Category::first();
        $description = str_repeat("\u{0985}", 45000);
        $import = TicketImport::factory()->create();
        Storage::disk('private')->put($import->storage_key,
            "customer_email,category,subject,description,priority\n{$customer->email},{$category->name},Unicode,{$description},critical\n");
        app(TicketImportProcessor::class)->execute($import->id);
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertSame(1, $import->processed_rows);
        $this->assertSame(0, $import->failed_rows);
        $this->assertSame($description, Ticket::sole()->description);
    }

    public function test_zero_search_is_applied_and_preserves_customer_scope(): void
    {
        $customer = User::factory()->create();
        $match = $this->ticket($customer, 'Issue 0');
        $this->ticket($customer, 'Unrelated issue');
        $this->ticket(User::factory()->create(), 'Private issue 0');
        $this->actingAs($customer)->getJson('/api/v1/tickets?q=0')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public function test_dashboard_agent_totals_respect_customer_and_agent_scopes(): void
    {
        $customer = User::factory()->create();
        $agent = User::factory()->create(['role' => 'agent']);
        $other = User::factory()->create(['role' => 'agent']);
        $agent->categories()->attach(Category::first());
        $this->ticket($customer)->update(['assigned_agent_id' => $agent->id]);
        $this->ticket($customer);
        $this->ticket(User::factory()->create())->update(['assigned_agent_id' => $other->id]);
        $this->actingAs($customer)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonCount(2, 'data.by_agent')->assertJsonMissing(['name' => $other->name]);
        $groups = $this->actingAs($agent)->getJson('/api/v1/dashboard')->assertOk()->json('data.by_agent');
        $this->assertSame(2, array_sum(array_column($groups, 'total')));
        $this->assertNotContains($other->id, array_column($groups, 'assigned_agent_id'));
        $groups = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/v1/dashboard')->assertOk()->json('data.by_agent');
        $this->assertSame(3, array_sum(array_column($groups, 'total')));
    }

    public function test_already_escalated_tickets_are_excluded_before_transaction_work(): void
    {
        $ticket = $this->ticket(User::factory()->create());
        $ticket->update(['sla_warning_at' => now()->subHour(), 'sla_due_at' => now()->subMinute()]);
        $service = app(SlaEvaluationService::class);
        $this->assertSame(1, $service->execute());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(0, $service->execute());
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
