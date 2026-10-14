<?php

namespace App\Domain\SLA\Services;

use App\Models\BusinessCalendar;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PublishSlaPolicy
{
    /** @param array{name:string,timezone:string,hours:array,exceptions?:array,rules:list<array{priority:string,resolution_minutes:int,warning_percent:int}>} $data */
    public function execute(User $actor, array $data): SlaPolicy
    {
        Gate::forUser($actor)->authorize('administer-support');

        return DB::transaction(function () use ($data): SlaPolicy {
            DB::table('support_locks')->where('name', 'sla_policy')->lockForUpdate()->first();
            $version = (SlaPolicy::max('version') ?? 0) + 1;
            $calendar = BusinessCalendar::create([
                'name' => $data['name'].' v'.$version, 'timezone' => $data['timezone'],
                'hours' => $data['hours'], 'exceptions' => $data['exceptions'] ?? [],
            ]);
            SlaPolicy::query()->where('is_active', true)->update(['is_active' => false]);
            $policy = SlaPolicy::create([
                'name' => $data['name'], 'version' => $version,
                'business_calendar_id' => $calendar->id, 'is_active' => true,
            ]);
            $policy->rules()->createMany($data['rules']);

            return $policy->load(['calendar', 'rules']);
        }, 3);
    }
}
