<?php

namespace App\Domain\Tickets\Actions;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SaveCategory
{
    /** @param array{name:string,is_active?:bool} $data */
    public function execute(User $actor, array $data, ?Category $category = null): Category
    {
        Gate::forUser($actor)->authorize('administer-support');
        $category ??= new Category;
        $category->fill($data)->save();

        return $category;
    }
}
