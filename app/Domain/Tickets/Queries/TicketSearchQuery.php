<?php

namespace App\Domain\Tickets\Queries;

use App\Domain\Tickets\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TicketSearchQuery
{
    public function execute(User $user, array $filters = []): Builder
    {
        $query = Ticket::query();
        if (! $user->isAdmin()) {
            if ($user->isAgent()) {
                $query->where(fn ($q) => $q->where('assigned_agent_id', $user->id)->orWhere(fn ($queue) => $queue->whereNull('assigned_agent_id')->whereIn('status', TicketStatus::activeValues())
                    ->whereIn('category_id', $user->categories()->select('categories.id'))));
            } else {
                $query->where('customer_id', $user->id);
            }
        }
        foreach (['status', 'priority', 'category_id', 'customer_id', 'assigned_agent_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if ($filters['unassigned'] ?? false) {
            $query->whereNull('assigned_agent_id');
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }
        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $query->where(function ($q) use ($term) {
                $q->where('ticket_number', $term);
                if ($q->getConnection()->getDriverName() === 'mysql') {
                    $q->orWhereFullText(['subject', 'description'], $term);
                } else {
                    $q->orWhere('subject', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%');
                }
            });
        }
        if (! empty($filters['sla_state'])) {
            $query->whereIn('status', TicketStatus::activeValues());
            match ($filters['sla_state']) {
                'breached' => $query->where('sla_due_at', '<=', now()),
                'warning' => $query->where('sla_warning_at', '<=', now())->where('sla_due_at', '>', now()),
                'within' => $query->where('sla_warning_at', '>', now()),
            };
        }

        return $query;
    }
}
