<?php

namespace Database\Seeders;

use App\Models\BusinessCalendar;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            DB::table('support_locks')->where('name', 'sla_policy')->lockForUpdate()->first();
            foreach (['Technical', 'Billing', 'General'] as $name) {
                Category::firstOrCreate(['name' => $name], ['is_active' => true]);
            }
            if (! SlaPolicy::exists()) {
                $calendar = BusinessCalendar::create(['name' => 'Dhaka business hours', 'timezone' => 'Asia/Dhaka', 'hours' => array_fill_keys([0, 1, 2, 3, 4], [['09:00', '18:00']]), 'exceptions' => []]);
                $policy = SlaPolicy::create(['name' => 'Standard resolution SLA', 'business_calendar_id' => $calendar->id, 'version' => 1, 'is_active' => true]);
                foreach (['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120] as $priority => $minutes) {
                    $policy->rules()->create(['priority' => $priority, 'resolution_minutes' => $minutes, 'warning_percent' => 80]);
                }
            }
            $email = config('support.admin_email');
            $password = config('support.admin_password');
            if (($email === null || $email === '') && ($password === null || $password === '')) {
                return;
            }
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || strlen($password) < 12) {
                throw new \InvalidArgumentException('Bootstrap administrator requires a valid email and a password of at least 12 characters.');
            }
            $created = ! User::where('email', $email)->exists();
            User::firstOrCreate(
                ['email' => $email],
                ['name' => 'Administrator', 'password' => $password, 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]
            );
            if ($created) {
                $this->command?->info('Administrator account created.');
            } else {
                $this->command?->line('Bootstrap account already exists; credentials unchanged.');
            }
        });
    }
}
