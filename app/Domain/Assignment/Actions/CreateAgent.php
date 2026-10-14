<?php

namespace App\Domain\Assignment\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateAgent
{
    /** @param array{name:string,email:string,password:string,category_ids:list<int>} $data */
    public function execute(User $actor, array $data): User
    {
        Gate::forUser($actor)->authorize('administer-support');

        return DB::transaction(function () use ($data): User {
            $agent = User::create([
                'name' => $data['name'], 'email' => $data['email'],
                'password' => $data['password'], 'role' => 'agent',
            ]);
            $agent->categories()->sync($data['category_ids']);

            return $agent->load('categories');
        }, 3);
    }
}
