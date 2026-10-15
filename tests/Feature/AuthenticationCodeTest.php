<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AuthenticationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_emailed_code_before_login(): void
    {
        Notification::fake();
        $payload = ['name' => 'Customer', 'email' => 'new@example.com', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'];

        $this->postJson('/api/v1/register', $payload)->assertCreated()->assertJsonPath('email', 'new@example.com');
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => $payload['password']])->assertUnprocessable();

        $notification = Notification::sent($user, AuthenticationCodeNotification::class)->first();
        $this->postJson('/api/v1/verify-email', ['email' => $user->email, 'code' => $notification->code])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_password_can_be_reset_with_emailed_code_only_once(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'customer@example.com']);

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $notification = Notification::sent($user, AuthenticationCodeNotification::class)->first();
        $payload = ['email' => $user->email, 'code' => $notification->code, 'password' => 'new-long-password-456', 'password_confirmation' => 'new-long-password-456'];
        $this->postJson('/api/v1/reset-password', $payload)->assertOk();

        $this->assertTrue(Hash::check('new-long-password-456', $user->fresh()->password));
        $this->postJson('/api/v1/reset-password', $payload)->assertUnprocessable();
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/forgot-password', ['email' => 'missing@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If the account exists, a verification code was sent.');
    }
}
