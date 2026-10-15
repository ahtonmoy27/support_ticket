<?php

namespace App\Domain\Assignment\Actions;

use App\Domain\Audit\Services\RecordActivity;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignTicket
{
    public function __construct(private RecordActivity $audit) {}

    public function execute(int $ticketId, User $actor, ?int $agentId = null, bool $claim = false): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $agentId, $claim) {
            Category::query()->whereKey(Ticket::query()->select('category_id')->whereKey($ticketId))->lockForUpdate()->firstOrFail();
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            Gate::forUser($actor)->authorize($claim ? 'claim' : 'assign', $ticket);
            abort_if(! $ticket->status->isActive() || ($claim && ($ticket->assigned_agent_id !== null || $ticket->status !== TicketStatus::Open)), 409, 'Ticket is no longer available for assignment.');
            $agent = User::query()->lockForUpdate()->findOrFail($claim ? $actor->id : $agentId);
            $this->assertEligible($agent, $ticket->category_id, $claim && $actor->isAdmin());
            $old = ['assigned_agent_id' => $ticket->assigned_agent_id, 'status' => $ticket->status->value];
            $ticket->update(['assigned_agent_id' => $agent->id, 'status' => TicketStatus::InProgress]);
            $this->audit->execute($ticket, $actor, $claim ? 'ticket.claimed' : 'ticket.assigned', $old, ['assigned_agent_id' => $agent->id, 'status' => $ticket->status->value]);

            return $ticket;
        }, 3);
    }

    public function autoAssign(Ticket $ticket, User $actor): void
    {
        $agents = User::query()->select('users.*')->join('agent_categories', 'agent_categories.user_id', '=', 'users.id')
            ->where('role', 'agent')->where('is_active', true)->where('is_available', true)
            ->where('agent_categories.category_id', $ticket->category_id)->orderBy('users.id')->lockForUpdate()->get();
        if ($agents->isEmpty()) {
            $categoryName = $ticket->relationLoaded('category')
                ? $ticket->category?->name
                : Category::query()->whereKey($ticket->category_id)->value('name');
            $categoryName = $categoryName ?? (string) $ticket->category_id;

            $this->audit->execute(
                $ticket,
                null,
                'ticket.auto_assignment_skipped',
                [],
                ['note' => "Auto-assignment skipped: no eligible agents found for category '{$categoryName}'"],
                'public',
                false
            );

            return;
        }
        /** A locking read sees current workloads even inside a repeatable-read transaction. */
        $workloads = Ticket::query()->whereIn('assigned_agent_id', $agents->modelKeys())
            ->whereIn('status', TicketStatus::activeValues())->selectRaw('assigned_agent_id, COUNT(*) as total')
            ->groupBy('assigned_agent_id')->lockForUpdate()->pluck('total', 'assigned_agent_id');
        $selected = $agents->sortBy(fn (User $agent): int => (int) $workloads->get($agent->id, 0))->first();
        if ($selected) {
            $ticket->update(['assigned_agent_id' => $selected->id, 'status' => TicketStatus::InProgress]);
            $this->audit->execute($ticket, $actor, 'ticket.assigned', [], ['assigned_agent_id' => $selected->id]);
        }
    }

    private function assertEligible(User $agent, int $categoryId, bool $allowAdmin): void
    {
        if (! $agent->is_active || ! $agent->is_available || ! (($allowAdmin && $agent->isAdmin()) || ($agent->isAgent() && $agent->categories()->whereKey($categoryId)->exists()))) {
            throw ValidationException::withMessages(['assigned_agent_id' => 'Choose an active, available agent eligible for this category.']);
        }
    }
}
