<?php
namespace App\Domain\Tickets\Actions;
use App\Models\Ticket;
use App\Models\User;
use App\Models\SlaRule;
use App\Domain\SLA\Services\SlaDeadlineCalculator;
use App\Domain\Audit\Services\RecordActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
class UpdateTicket {
    public function __construct(private SlaDeadlineCalculator $calculator, private RecordActivity $audit) {}
    public function execute(int $id, User $actor, array $data): Ticket {
        return DB::transaction(function () use ($id,$actor,$data) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update',$ticket);
            abort_if(isset($data['priority']) && $actor->role === 'customer',403);
            $old = $ticket->only(['subject','description','priority','sla_due_at','sla_warning_at']);
            if (isset($data['priority']) && $data['priority'] !== $ticket->priority->value) {
                if ($ticket->sla_due_at->lessThanOrEqualTo(now()) && ! $ticket->sla_breached_at) { $ticket->sla_breached_at = $ticket->sla_due_at; }
                $rule = SlaRule::query()->where('sla_policy_id',$ticket->slaRule->sla_policy_id)->where('priority',$data['priority'])->firstOrFail();
                $data['sla_rule_id'] = $rule->id; $data['sla_target_minutes'] = $rule->resolution_minutes; $data['sla_warning_percent'] = $rule->warning_percent;
                $data['sla_due_at'] = $this->calculator->calculate($ticket->sla_started_at,$rule->resolution_minutes,$ticket->sla_calendar_snapshot);
                $data['sla_warning_at'] = $this->calculator->calculate($ticket->sla_started_at,(int) ceil($rule->resolution_minutes*$rule->warning_percent/100),$ticket->sla_calendar_snapshot);
                if ($data['sla_due_at']->lessThanOrEqualTo(now()) && ! $ticket->sla_breached_at) { $ticket->sla_breached_at = $data['sla_due_at']; }
            }
            $ticket->fill($data)->save();
            $this->audit->execute($ticket,$actor,'ticket.updated',$old,$ticket->only(array_keys($old)));
            return $ticket;
        },3);
    }
}