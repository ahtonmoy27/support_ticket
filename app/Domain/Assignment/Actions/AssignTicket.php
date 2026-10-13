<?php
namespace App\Domain\Assignment\Actions;
use App\Models\Ticket;
use App\Models\User;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Domain\Audit\Services\RecordActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
class AssignTicket {
    public function __construct(private RecordActivity $audit) {}
    public function execute(int $ticketId, User $actor, ?int $agentId = null, bool $claim = false): Ticket {
        return DB::transaction(function () use ($ticketId, $actor, $agentId, $claim) {
            DB::table('support_locks')->where('name','assignment')->lockForUpdate()->first();
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            Gate::forUser($actor)->authorize($claim ? 'claim' : 'assign', $ticket);
            abort_if(! $ticket->status->isActive() || ($claim && ($ticket->assigned_agent_id !== null || $ticket->status !== TicketStatus::Open)), 409, 'Ticket is no longer available for assignment.');
            $agent = User::query()->lockForUpdate()->findOrFail($claim ? $actor->id : $agentId);
            $this->assertEligible($agent, $ticket->category_id, $claim && $actor->isAdmin());
            $old = ['assigned_agent_id'=>$ticket->assigned_agent_id,'status'=>$ticket->status->value];
            $ticket->update(['assigned_agent_id'=>$agent->id,'status'=>TicketStatus::InProgress]);
            $this->audit->execute($ticket,$actor,$claim ? 'ticket.claimed' : 'ticket.assigned',$old,['assigned_agent_id'=>$agent->id,'status'=>$ticket->status->value]);
            return $ticket;
        }, 3);
    }
    public function autoAssign(Ticket $ticket, User $actor): void {
        $agents = User::query()->where('role','agent')->where('is_active',true)->where('is_available',true)
            ->whereHas('categories', fn ($q) => $q->where('categories.id',$ticket->category_id))->orderBy('id')->lockForUpdate()->get();
        $selected = $agents->sortBy(fn (User $agent) => $agent->assignedTickets()->whereIn('status',TicketStatus::activeValues())->count())->first();
        if ($selected) {
            $ticket->update(['assigned_agent_id'=>$selected->id,'status'=>TicketStatus::InProgress]);
            $this->audit->execute($ticket,$actor,'ticket.assigned',[],['assigned_agent_id'=>$selected->id]);
        }
    }
    private function assertEligible(User $agent, int $categoryId, bool $allowAdmin): void {
        if (! $agent->is_active || ! $agent->is_available || ! (($allowAdmin && $agent->isAdmin()) || ($agent->isAgent() && $agent->categories()->whereKey($categoryId)->exists()))) {
            throw ValidationException::withMessages(['assigned_agent_id'=>'Choose an active, available agent eligible for this category.']);
        }
    }
}
