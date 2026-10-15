<?php

namespace App\Domain\Auth\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AuthenticationCodeService
{
    private const int LifetimeMinutes = 15;

    public function issue(string $email, string $purpose): string
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($email, $purpose), hash('sha256', $code), now()->addMinutes(self::LifetimeMinutes));

        return $code;
    }

    public function verify(string $email, string $purpose, string $code): void
    {
        $key = $this->key($email, $purpose);
        $expectedHash = Cache::get($key);

        if (! is_string($expectedHash) || ! hash_equals($expectedHash, hash('sha256', $code))) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired verification code.']);
        }

        Cache::forget($key);
    }

    private function key(string $email, string $purpose): string
    {
        return 'authentication-code:'.$purpose.':'.hash('sha256', mb_strtolower($email));
    }
}
