<?php

namespace App\Domain\Assignment\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateAgent
{
    /** @param array{name?:string,is_active?:bool,is_available?:bool,category_ids?:list<int>} $data */
    public function execute(User $actor, int $agentId, array $data): User
    {
        Gate::forUser($actor)->authorize('administer-support');

        return DB::transaction(function () use ($agentId, $data): User {
            $agent = User::query()->lockForUpdate()->findOrFail($agentId);
            abort_unless($agent->isAgent(), 404);
            $agent->fill(array_intersect_key($data, array_flip(['name', 'is_active', 'is_available'])))->save();
            if (isset($data['category_ids'])) {
                $agent->categories()->sync($data['category_ids']);
            }

            return $agent->load('categories');
        }, 3);
    }
}
