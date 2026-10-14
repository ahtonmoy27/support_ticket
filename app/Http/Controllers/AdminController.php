<?php

namespace App\Http\Controllers;

use App\Domain\Assignment\Actions\CreateAgent;
use App\Domain\Assignment\Actions\UpdateAgent;
use App\Domain\SLA\Services\PublishSlaPolicy;
use App\Domain\Tickets\Actions\SaveCategory;
use App\Http\Requests\CreateAgentRequest;
use App\Http\Requests\SaveCategoryRequest;
use App\Http\Requests\SlaPolicyRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\SlaPolicyResource;
use App\Http\Resources\UserResource;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        return response()->json(['data' => CategoryResource::collection(Category::query()->when(! $request->user()->isAdmin(), fn ($q) => $q->where('is_active', true))->orderBy('name')->get())]);
    }

    public function customers(Request $request): JsonResponse
    {
        $request->validate(['q' => ['sometimes', 'string', 'max:100']]);

        $customers = User::query()
            ->where('role', 'customer')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.trim($request->q).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('name')
            ->select(['id', 'name', 'email', 'is_active', 'created_at'])
            ->paginate(25);

        return UserResource::collection($customers)->response();
    }

    public function saveCategory(SaveCategoryRequest $request, SaveCategory $action, ?Category $category = null): JsonResponse
    {
        $data = $request->validated();
        $category = $action->execute($request->user(), $data, $category);

        return response()->json(['data' => new CategoryResource($category)]);
    }

    public function agents(): JsonResponse
    {
        return UserResource::collection(User::query()->where('role', 'agent')->with('categories')->orderBy('id')->paginate(25))->response();
    }

    public function createAgent(CreateAgentRequest $request, CreateAgent $action): JsonResponse
    {
        $data = $request->validated();
        $agent = $action->execute($request->user(), $data);

        return response()->json(['data' => new UserResource($agent)], 201);
    }

    public function updateAgent(UpdateAgentRequest $request, User $agent, UpdateAgent $action): JsonResponse
    {
        abort_unless($agent->isAgent(), 404);
        $data = $request->validated();
        $agent = $action->execute($request->user(), $agent->id, $data);

        return response()->json(['data' => new UserResource($agent)]);
    }

    public function policies(): JsonResponse
    {
        return SlaPolicyResource::collection(SlaPolicy::query()->with(['calendar', 'rules'])->orderByDesc('id')->paginate(25))->response();
    }

    public function createPolicy(SlaPolicyRequest $request, PublishSlaPolicy $action): JsonResponse
    {
        $policy = $action->execute($request->user(), $request->validated());

        return response()->json(['data' => new SlaPolicyResource($policy)], 201);
    }
}
