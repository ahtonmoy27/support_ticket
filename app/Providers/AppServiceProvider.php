<?php
namespace App\Providers;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
class AppServiceProvider extends ServiceProvider {
    public function register(): void {}
    public function boot(): void {
        Gate::policy(Ticket::class,TicketPolicy::class);
        Gate::define('administer-support',fn (User $user) => $user->is_active && $user->isAdmin());
        RateLimiter::for('login',fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('support',fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->id));
        RateLimiter::for('imports',fn (Request $request) => Limit::perHour(10)->by((string) $request->user()?->id));
    }
}