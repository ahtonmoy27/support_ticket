<?php

namespace App\Http\Controllers;

use App\Domain\Tickets\Enums\TicketStatus;
use App\Domain\Tickets\Queries\TicketSearchQuery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TicketSearchQuery $query): JsonResponse
    {
        $user = $request->user();
        $data = Cache::remember('support.dashboard.'.$user->id.'.'.$user->role, 30, function () use ($user, $query) {
            $base = $query->execute($user);
            $agents = (clone $base)->selectRaw('assigned_agent_id, COUNT(*) as total')
                ->groupBy('assigned_agent_id')->get();
            $names = User::query()->whereIn('id', $agents->pluck('assigned_agent_id')->filter())
                ->pluck('name', 'id');

            return [
                'by_status' => (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                'by_priority' => (clone $base)->selectRaw('priority, COUNT(*) as total')->groupBy('priority')->pluck('total', 'priority'),
                'by_agent' => $agents->map(fn ($group): array => [
                    'assigned_agent_id' => $group->assigned_agent_id,
                    'name' => $group->assigned_agent_id === null ? 'Unassigned' : $names->get($group->assigned_agent_id, 'Unknown agent'),
                    'total' => (int) $group->total,
                ]),
                'unassigned' => (clone $base)->whereNull('assigned_agent_id')->whereIn('status', TicketStatus::activeValues())->count(),
                'sla_breached' => (clone $base)->whereIn('status', TicketStatus::activeValues())->where('sla_due_at', '<=', now())->count(),
                'sla_warning' => (clone $base)->whereIn('status', TicketStatus::activeValues())->where('sla_due_at', '>', now())->where('sla_warning_at', '<=', now())->count(),
            ];
        });

        return response()->json(['data' => $data]);
    }
}
