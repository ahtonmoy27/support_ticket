<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Services\AuthenticationCodeService;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AuthenticationCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, AuthenticationCodeService $codes): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users'], 'password' => ['required', 'confirmed', 'min:12'], 'role' => ['prohibited']]);
        $user = User::create($data + ['role' => 'customer']);
        $user->notify(new AuthenticationCodeNotification($codes->issue($user->email, 'registration'), 'registration'));

        return response()->json(['message' => 'Account created. Check your email for the verification code.', 'email' => $user->email], 201);
    }

    public function verifyEmail(Request $request, AuthenticationCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6']]);
        $user = User::where('email', $data['email'])->firstOrFail();
        $codes->verify($user->email, 'registration', $data['code']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['data' => new UserResource($user)]);
    }

    public function resendVerification(Request $request, AuthenticationCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->whereNull('email_verified_at')->first();
        if ($user !== null) {
            $user->notify(new AuthenticationCodeNotification($codes->issue($user->email, 'registration'), 'registration'));
        }

        return response()->json(['message' => 'If an unverified account exists, a new code was sent.']);
    }

    public function forgotPassword(Request $request, AuthenticationCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->first();
        if ($user !== null) {
            $user->notify(new AuthenticationCodeNotification($codes->issue($user->email, 'password-reset'), 'password-reset'));
        }

        return response()->json(['message' => 'If the account exists, a verification code was sent.']);
    }

    public function resetPassword(Request $request, AuthenticationCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', 'min:12'],
        ]);
        $user = User::where('email', $data['email'])->first();
        if ($user === null) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired verification code.']);
        }
        $codes->verify($user->email, 'password-reset', $data['code']);
        $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($data + ['is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }
        if ($request->user()?->email_verified_at === null) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Verify your email before signing in.']);
        }
        $request->session()->regenerate();

        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }
}
