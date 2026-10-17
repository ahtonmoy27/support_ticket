<?php

namespace App\Domain\Tickets\Actions;

use App\Domain\Audit\Services\RecordActivity;
use App\Domain\SLA\Services\SlaDeadlineCalculator;
use App\Models\SlaRule;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateTicket
{
    public function __construct(private SlaDeadlineCalculator $calculator, private RecordActivity $audit) {}

    /**
     * Updates ticket content or priority. Recalculates SLA deadline if priority changes.
     */
    public function execute(int $id, User $actor, array $data): Ticket
    {
        return DB::transaction(function () use ($id, $actor, $data) {
            // 1. Lock ticket and check update authorization
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $ticket);

            // Customers cannot change ticket priority directly
            abort_if(isset($data['priority']) && $actor->role === 'customer', 403);
            $old = $ticket->only(['subject', 'description', 'priority', 'sla_due_at', 'sla_warning_at']);

            // 2. If priority changed, recalculate SLA targets and deadlines
            if (isset($data['priority']) && $data['priority'] !== $ticket->priority->value) {
                if ($ticket->sla_due_at && $ticket->sla_due_at->lessThanOrEqualTo(now()) && ! $ticket->sla_breached_at) {
                    $ticket->sla_breached_at = $ticket->sla_due_at;
                }
                $policyId = $ticket->slaRule?->sla_policy_id
                    ?? SlaRule::query()->where('priority', $data['priority'])->whereHas('policy', fn ($q) => $q->where('is_active', true))->value('sla_policy_id');
                $rule = SlaRule::query()->where('sla_policy_id', $policyId)->where('priority', $data['priority'])->firstOrFail();
                $calendar = $ticket->sla_calendar_snapshot ?? $this->calculator->snapshot($rule->policy->calendar);
                $start = $ticket->sla_started_at ? $ticket->sla_started_at->toImmutable() : now()->toImmutable();

                $data['sla_rule_id'] = $rule->id;
                $data['sla_target_minutes'] = $rule->resolution_minutes;
                $data['sla_warning_percent'] = $rule->warning_percent;
                $data['sla_calendar_snapshot'] = $calendar;
                $data['sla_started_at'] = $start;
                $data['sla_due_at'] = $this->calculator->calculate($start, $rule->resolution_minutes, $calendar);
                $data['sla_warning_at'] = $this->calculator->calculate($start, (int) ceil($rule->resolution_minutes * $rule->warning_percent / 100), $calendar);
                if ($data['sla_due_at']->lessThanOrEqualTo(now()) && ! $ticket->sla_breached_at) {
                    $ticket->sla_breached_at = $data['sla_due_at'];
                }
            }

            // 3. Save ticket and record changes in activity log
            $ticket->fill($data)->save();
            $this->audit->execute($ticket, $actor, 'ticket.updated', $old, $ticket->only(array_keys($old)));

            return $ticket;
        }, 3);
    }
}
