<?php

namespace App\Domain\Auth\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthenticationCodeService
{
    private const int LifetimeMinutes = 15;

    public function issue(string $email, string $purpose): string
    {
        return DB::transaction(function () use ($email, $purpose): string {
            $user = User::where('email', $email)->lockForUpdate()->firstOrFail();
            $query = DB::table('authentication_codes')->where('user_id', $user->id)->where('purpose', $purpose);
            $previous = $query->first();
            if ($previous !== null && now()->subMinute()->lessThan($previous->updated_at)) {
                throw ValidationException::withMessages(['email' => 'Please wait 60 seconds before requesting another code.']);
            }
            do {
                $code = (string) random_int(100000, 999999);
            } while ($previous !== null && hash_equals($previous->code_hash, $this->hash($code)));
            DB::table('authentication_codes')->updateOrInsert(
                ['user_id' => $user->id, 'purpose' => $purpose],
                ['code_hash' => $this->hash($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(self::LifetimeMinutes), 'created_at' => now(), 'updated_at' => now()],
            );

            return $code;
        }, 3);
    }

    /** @param Closure(User): void|null $onVerified */
    public function verify(string $email, string $purpose, string $code, ?Closure $onVerified = null): void
    {
        $verified = DB::transaction(function () use ($email, $purpose, $code, $onVerified): bool {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if ($user === null || ! $user->is_active) {
                return false;
            }
            $query = DB::table('authentication_codes')->where('user_id', $user->id)->where('purpose', $purpose);
            $record = $query->lockForUpdate()->first();
            if ($record === null || now()->greaterThanOrEqualTo($record->expires_at) || $record->attempts >= 5) {
                return false;
            }
            if (! hash_equals($record->code_hash, $this->hash($code))) {
                $query->increment('attempts');

                return false;
            }
            if ($onVerified !== null) {
                $onVerified($user);
            }
            $query->delete();

            return true;
        }, 3);

        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired verification code.']);
        }
    }

    public function isCurrent(int $userId, string $purpose, string $code): bool
    {
        return DB::table('authentication_codes')->where('user_id', $userId)->where('purpose', $purpose)
            ->where('code_hash', $this->hash($code))->where('expires_at', '>', now())->where('attempts', '<', 5)->exists();
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
