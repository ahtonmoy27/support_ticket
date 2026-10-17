<?php

namespace Tests\Feature;

use App\Domain\Tickets\Actions\CreateTicket;
use App\Http\Resources\TicketResource;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\AuthenticationCodeNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['subject' => 'Cannot sign in', 'description' => 'Login fails with an error.', 'category_id' => Category::first()->id, 'priority' => 'critical'], $extra);
    }

    private function ticket(User $customer): Ticket
    {
        return app(CreateTicket::class)->execute($customer, $this->payload());
    }

    private function agent(int $categoryId): User
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $agent->categories()->attach($categoryId);

        return $agent;
    }

    public function test_authentication_and_customer_registration_cannot_elevate_roles(): void
    {
        Notification::fake();
        $this->getJson('/api/v1/tickets')->assertUnauthorized();
        $data = ['name' => 'Customer', 'email' => 'new@example.com', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'];
        $this->postJson('/api/v1/register', $data + ['role' => 'admin'])->assertUnprocessable();
        $this->postJson('/api/v1/register', $data)->assertCreated();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $code = Notification::sent($user, AuthenticationCodeNotification::class)->first()->code;
        $this->postJson('/api/v1/verify-email', ['email' => $user->email, 'code' => $code])->assertOk()->assertJsonPath('data.role', 'customer');
        $this->getJson('/api/v1/me')->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->postJson('/api/v1/login', ['email' => 'new@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => 'new@example.com', 'password' => 'long-password-123'])->assertOk();
    }

    public function test_inactive_accounts_and_login_throttling(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);
        $this->actingAs($inactive)->getJson('/api/v1/tickets')->assertForbidden();
        auth()->logout();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'bad@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => 'bad@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_creation_numbers_snapshots_and_audit_are_persisted(): void
    {
        $customer = User::factory()->create();
        $one = $this->actingAs($customer)->postJson('/api/v1/tickets', $this->payload())->assertCreated();
        $two = $this->postJson('/api/v1/tickets', $this->payload())->assertCreated();
        $this->assertNotSame($one->json('data.ticket_number'), $two->json('data.ticket_number'));
        $ticket = Ticket::findOrFail($one->json('data.id'));
        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{6,}$/', $ticket->ticket_number);
        $this->assertSame(120, $ticket->sla_target_minutes);
        $this->assertSame($customer->id, $ticket->created_by);
        $this->assertSame(1, $ticket->activities()->where('action', 'ticket.created')->count());
    }

    public function test_customers_cannot_forge_ownership_priority_updates_or_assignment(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $ticket = $this->ticket($customer);
        $this->actingAs($customer)->postJson('/api/v1/tickets', $this->payload(['customer_id' => $other->id]))->assertUnprocessable();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['priority' => 'low'])->assertUnprocessable();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/claim')->assertForbidden();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/assign', ['assigned_agent_id' => $customer->id])->assertForbidden();
    }

    public function test_customer_ticket_scope_is_enforced_on_search_dashboard_and_details(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ticket = $this->ticket($owner);
        $this->ticket($other);
        $this->actingAs($other)->getJson('/api/v1/tickets/'.$ticket->id)->assertForbidden();
        $this->getJson('/api/v1/tickets?q='.$ticket->ticket_number)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tickets')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.by_status.open', 1);
    }

    public function test_claim_returns_conflict_for_second_eligible_agent_and_rejects_ineligible_agent(): void
    {
        $ticket = $this->ticket(User::factory()->create());
        $one = $this->agent($ticket->category_id);
        $two = $this->agent($ticket->category_id);
        $ineligible = User::factory()->create(['role' => 'agent']);
        $this->actingAs($ineligible)->postJson('/api/v1/tickets/'.$ticket->id.'/claim')->assertForbidden();
        $this->actingAs($one)->postJson('/api/v1/tickets/'.$ticket->id.'/claim')->assertOk()->assertJsonPath('data.assigned_agent_id', $one->id);
        $this->actingAs($two)->postJson('/api/v1/tickets/'.$ticket->id.'/claim')->assertConflict();
        $this->assertSame(1, $ticket->activities()->where('action', 'ticket.claimed')->count());
    }

    public function test_manual_assignment_validates_eligibility_and_agent_can_resolve(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket(User::factory()->create());
        $ineligible = User::factory()->create(['role' => 'agent']);
        $eligible = $this->agent($ticket->category_id);
        $this->actingAs($admin)->postJson('/api/v1/tickets/'.$ticket->id.'/assign', ['assigned_agent_id' => $ineligible->id])->assertUnprocessable();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/assign', ['assigned_agent_id' => $eligible->id])->assertOk();
        $this->actingAs($ineligible)->postJson('/api/v1/tickets/'.$ticket->id.'/resolve')->assertForbidden();
        $this->actingAs($eligible)->postJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'waiting_for_customer'])->assertOk();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/resolve')->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/close')->assertForbidden();
        $this->actingAs($ticket->customer)->postJson('/api/v1/tickets/'.$ticket->id.'/close')->assertOk();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['subject' => 'changed'])->assertForbidden();
    }

    public function test_invalid_status_transitions_and_resolved_replies_are_rejected(): void
    {
        $ticket = $this->ticket(User::factory()->create());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($ticket->customer)->postJson('/api/v1/tickets/'.$ticket->id.'/close')->assertConflict();
        $this->actingAs($admin)->postJson('/api/v1/tickets/'.$ticket->id.'/resolve')->assertOk();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'open'])->assertConflict();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Late reply', 'visibility' => 'public'])->assertConflict();
    }

    public function test_internal_notes_and_private_attachments_never_reach_customer(): void
    {
        Storage::fake('private');
        $ticket = $this->ticket(User::factory()->create());
        $agent = $this->agent($ticket->category_id);
        $this->actingAs($agent)->postJson('/api/v1/tickets/'.$ticket->id.'/claim')->assertOk();
        $note = $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Secret internal note', 'visibility' => 'internal'])->assertCreated();
        $attachment = $this->postJson('/api/v1/tickets/'.$ticket->id.'/attachments', [
            'file' => UploadedFile::fake()->createWithContent('note.txt', 'private note'), 'ticket_message_id' => $note->json('data.id'),
        ])->assertCreated()->assertJsonMissingPath('data.storage_key');
        $this->actingAs($ticket->customer)->getJson('/api/v1/tickets/'.$ticket->id.'/messages')->assertJsonCount(0, 'data')->assertDontSee('Secret internal note');
        $this->getJson('/api/v1/tickets/'.$ticket->id.'/attachments')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tickets/'.$ticket->id.'/activities')->assertDontSee('ticket.replied')->assertDontSee('ticket.attachment_added');
        $this->getJson('/api/v1/attachments/'.$attachment->json('data.id').'/download')->assertForbidden();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Forged internal', 'visibility' => 'internal'])->assertForbidden();
    }

    public function test_public_attachment_download_is_authorized_and_unsafe_types_rejected(): void
    {
        Storage::fake('private');
        $owner = User::factory()->create();
        $ticket = $this->ticket($owner);
        $response = $this->actingAs($owner)->postJson('/api/v1/tickets/'.$ticket->id.'/attachments', ['file' => UploadedFile::fake()->createWithContent('readme.txt', 'plain text')])->assertCreated();
        $id = $response->json('data.id');
        $this->get('/api/v1/attachments/'.$id.'/download')->assertOk()->assertDownload('readme.txt');
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/attachments', ['file' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;')])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/attachments/'.$id.'/download')->assertForbidden();
    }

    public function test_automatic_assignment_balances_workload_with_deterministic_ties(): void
    {
        $category = Category::first();
        $one = $this->agent($category->id);
        $two = $this->agent($category->id);
        config(['support.auto_assign' => true]);
        $customer = User::factory()->create();
        $first = $this->ticket($customer);
        $second = $this->ticket($customer);
        $third = $this->ticket($customer);
        $this->assertSame($one->id, $first->assigned_agent_id);
        $this->assertSame($two->id, $second->assigned_agent_id);
        $this->assertSame($one->id, $third->assigned_agent_id);
    }

    public function test_admin_can_create_on_behalf_and_manage_agents_categories(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('/api/v1/admin/agents')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/tickets', $this->payload(['customer_id' => $customer->id]))->assertCreated()->assertJsonPath('data.customer_id', $customer->id);
        $response = $this->postJson('/api/v1/admin/agents', ['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'long-password-123', 'category_ids' => [Category::first()->id]])->assertCreated();
        $this->patchJson('/api/v1/admin/agents/'.$response->json('data.id'), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/v1/admin/categories', ['name' => 'New category'])->assertOk();
    }

    public function test_priority_change_recalculates_from_original_start_and_preserves_breach(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00', 'Asia/Dhaka'));
        $ticket = $this->ticket(User::factory()->create());
        $original = $ticket->sla_started_at;
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Dhaka'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson('/api/v1/tickets/'.$ticket->id, ['priority' => 'low'])->assertOk();
        $ticket->refresh();
        $this->assertTrue($ticket->sla_started_at->equalTo($original));
        $this->assertNotNull($ticket->sla_breached_at);
        $this->assertSame('low', $ticket->priority->value);
    }

    public function test_notification_read_is_scoped_to_current_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['event' => 'test']]);
        $id = $owner->notifications()->first()->id;
        $this->actingAs($other)->patchJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
        $this->actingAs($owner)->patchJson('/api/v1/notifications/'.$id.'/read')->assertOk();
    }

    public function test_unauthenticated_backend_requests_return_json_without_accept_header(): void
    {
        $this->get('/api/v1/tickets')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_session_mutations_require_csrf_outside_testing_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        try {
            $credentials = ['email' => 'nobody@example.com', 'password' => 'invalid'];
            $this->postJson('/api/v1/login', $credentials)->assertStatus(419);
            $this->withSession(['_token' => 'known-csrf-token'])->postJson('/api/v1/login', $credentials, ['X-CSRF-TOKEN' => 'known-csrf-token'])->assertUnprocessable();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_creator_is_distinct_from_customer_and_exposed_without_private_user_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $response = $this->actingAs($admin)->postJson('/api/v1/tickets', $this->payload(['customer_id' => $customer->id]))
            ->assertCreated()->assertJsonPath('data.created_by', $admin->id)->assertJsonPath('data.customer_id', $customer->id);
        $this->actingAs($customer)->getJson('/api/v1/tickets/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.creator.id', $admin->id)->assertJsonPath('data.creator.name', $admin->name)
            ->assertJsonMissingPath('data.creator.email')->assertJsonMissingPath('data.creator.password');
        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('data.0.creator.id', $admin->id);
    }

    public function test_auto_assignment_logs_audit_note_when_no_eligible_agents_for_category(): void
    {
        config(['support.auto_assign' => true]);
        $category = Category::factory()->create(['name' => 'DevOps Special', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer']);

        $response = $this->actingAs($customer)->postJson('/api/v1/tickets', $this->payload([
            'category_id' => $category->id,
        ]))->assertCreated();

        $ticket = Ticket::findOrFail($response->json('data.id'));
        $this->assertNull($ticket->assigned_agent_id);
        $this->assertSame('open', $ticket->status->value);

        $activity = $ticket->activities()->where('action', 'ticket.auto_assignment_skipped')->first();
        $this->assertNotNull($activity);
        $this->assertSame("Auto-assignment skipped: no eligible agents found for category 'DevOps Special'", $activity->new_values['note']);
    }

    public function test_ticket_with_null_sla_due_at_resolves_and_serializes_without_errors(): void
    {
        $ticket = new Ticket([
            'id' => 999,
            'ticket_number' => 'TKT-2026-000999',
            'subject' => 'Test',
            'description' => 'Test',
            'priority' => 'medium',
            'status' => 'open',
            'category_id' => 1,
            'created_by' => 1,
            'customer_id' => 1,
            'sla_due_at' => null,
            'sla_warning_at' => null,
            'sla_breached_at' => null,
        ]);

        $resource = (new TicketResource($ticket))->toArray(request());
        $this->assertSame('within', $resource['sla_state']);

        $ticket->resolved_at = now();
        $resourceResolved = (new TicketResource($ticket))->toArray(request());
        $this->assertSame('within', $resourceResolved['sla_state']);
    }
}
