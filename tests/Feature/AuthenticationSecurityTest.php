<?php

namespace Tests\Feature;

use App\Domain\Assignment\Actions\CreateAgent;
use App\Domain\Auth\Services\AuthenticationCodeService;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Models\Category;
use App\Models\User;
use App\Notifications\AuthenticationCodeNotification;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_survives_cache_clear_and_is_consumed_once(): void
    {
        $user = User::factory()->unverified()->create();
        $codes = app(AuthenticationCodeService::class);
        $code = $codes->issue($user->email, 'registration');
        Cache::flush();
        $this->assertNotSame($code, DB::table('authentication_codes')->value('code_hash'));
        $codes->verify($user->email, 'registration', $code);
        $this->expectException(ValidationException::class);
        $codes->verify($user->email, 'registration', $code);
    }

    public function test_five_failed_attempts_lock_code_even_with_correct_next_attempt(): void
    {
        $user = User::factory()->create();
        $codes = app(AuthenticationCodeService::class);
        $code = $codes->issue($user->email, 'password-reset');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $codes->verify($user->email, 'password-reset', '000000');
                $this->fail('Invalid code accepted.');
            } catch (ValidationException) {
                $this->assertSame($attempt + 1, (int) DB::table('authentication_codes')->value('attempts'));
            }
        }
        $this->expectException(ValidationException::class);
        $codes->verify($user->email, 'password-reset', $code);
    }

    public function test_code_expires_at_fifteen_minutes(): void
    {
        $user = User::factory()->create();
        $codes = app(AuthenticationCodeService::class);
        $code = $codes->issue($user->email, 'registration');
        $this->travel(14)->minutes();
        $this->assertTrue($codes->isCurrent($user->id, 'registration', $code));
        $this->travel(1)->minutes();
        $this->expectException(ValidationException::class);
        $codes->verify($user->email, 'registration', $code);
    }

    public function test_resending_invalidates_old_code_and_delayed_notification(): void
    {
        $user = User::factory()->create();
        $codes = app(AuthenticationCodeService::class);
        $old = $codes->issue($user->email, 'registration');
        $this->travel(61)->seconds();
        $new = $codes->issue($user->email, 'registration');
        $this->assertFalse((new AuthenticationCodeNotification($old, 'registration'))->shouldSend($user, 'mail'));
        $this->assertTrue($codes->isCurrent($user->id, 'registration', $new));
    }

    public function test_resend_cooldown_is_independent_of_ip(): void
    {
        $user = User::factory()->create();
        $codes = app(AuthenticationCodeService::class);
        $codes->issue($user->email, 'registration');
        $this->expectException(ValidationException::class);
        $codes->issue($user->email, 'registration');
    }

    public function test_inactive_account_cannot_sign_in_using_verification_code(): void
    {
        $user = User::factory()->unverified()->create();
        $code = app(AuthenticationCodeService::class)->issue($user->email, 'registration');
        $user->update(['is_active' => false]);
        $this->postJson('/api/v1/verify-email', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertGuest();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_failed_password_update_does_not_consume_code(): void
    {
        $user = User::factory()->create();
        $codes = app(AuthenticationCodeService::class);
        $code = $codes->issue($user->email, 'password-reset');
        try {
            $codes->verify($user->email, 'password-reset', $code, function (User $user): void {
                $user->update(['name' => 'Should roll back']);
                throw new \RuntimeException('Write failed');
            });
            $this->fail('Expected failure.');
        } catch (\RuntimeException) {
            $this->assertNotSame('Should roll back', $user->fresh()->name);
            $this->assertTrue($codes->isCurrent($user->id, 'password-reset', $code));
        }
    }

    public function test_registration_queues_encrypted_mail_instead_of_calling_smtp(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/register', ['name' => 'Customer', 'email' => 'queued@example.com', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'])->assertCreated();
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->queue === 'notifications' && $job->shouldBeEncrypted);
    }

    public function test_password_reset_removes_database_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $code = app(AuthenticationCodeService::class)->issue($user->email, 'password-reset');
        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'code' => $code, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_admin_created_agent_can_log_in(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $agent = app(CreateAgent::class)->execute($admin, ['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'agent-password-123', 'category_ids' => []]);
        $this->assertNotNull($agent->email_verified_at);
        $this->postJson('/api/v1/login', ['email' => $agent->email, 'password' => 'agent-password-123'])->assertOk();
    }

    public function test_dashboard_reflects_new_ticket_immediately(): void
    {
        config(['support.admin_email' => null, 'support.admin_password' => null, 'support.auto_assign' => false]);
        $this->seed(SupportSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.unassigned', 0);
        app(CreateTicket::class)->execute($user, ['subject' => 'Fresh dashboard', 'description' => 'No stale count', 'priority' => 'critical', 'category_id' => Category::first()->id]);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.unassigned', 1);
    }
}
