<?php

namespace App\Domain\Tickets\Actions;

use App\Domain\Assignment\Actions\AssignTicket;
use App\Domain\Audit\Services\RecordActivity;
use App\Domain\SLA\Services\SlaDeadlineCalculator;
use App\Models\Category;
use App\Models\SlaRule;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateTicket
{
    public function __construct(private SlaDeadlineCalculator $calculator, private AssignTicket $assignment, private RecordActivity $audit) {}

    /**
     * Creates a new support ticket with calculated SLA deadlines and auto-assignment.
     */
    public function execute(User $actor, array $data): Ticket
    {
        Gate::forUser($actor)->authorize('create', Ticket::class);

        return DB::transaction(function () use ($actor, $data) {
            // 1. Verify category is valid and active
            $category = Category::query()->whereKey($data['category_id'])->lockForUpdate()->first();
            if (! $category?->is_active) {
                throw ValidationException::withMessages(['category_id' => 'Category is inactive.']);
            }

            // 2. Validate customer (admins can create tickets on behalf of customers)
            $customerId = $data['customer_id'] ?? $actor->id;
            if ($customerId !== $actor->id && ! $actor->isAdmin()) {
                abort(403, 'Only administrators can create tickets for another user.');
            }
            $customer = User::query()->where('is_active', true)->findOrFail($customerId);
            if ($customerId !== $actor->id && $customer->role !== 'customer') {
                throw ValidationException::withMessages(['customer_id' => 'Select a customer.']);
            }

            // 3. Find active SLA rule matching priority and calculate SLA deadlines
            $rule = SlaRule::query()->with('policy.calendar')->where('priority', $data['priority'])->whereHas('policy', fn ($q) => $q->where('is_active', true))->orderByDesc('id')->first();
            if (! $rule) {
                throw ValidationException::withMessages(['priority' => 'No active SLA policy is configured.']);
            }
            $start = CarbonImmutable::now('UTC');
            $calendar = $this->calculator->snapshot($rule->policy->calendar);

            // 4. Create ticket with initial 'open' status and SLA snapshot
            $ticket = Ticket::create([
                'customer_id' => $customerId, 'created_by' => $actor->id, 'category_id' => $data['category_id'],
                'subject' => $data['subject'], 'description' => $data['description'], 'priority' => $data['priority'], 'status' => 'open',
                'sla_rule_id' => $rule->id, 'sla_target_minutes' => $rule->resolution_minutes, 'sla_warning_percent' => $rule->warning_percent,
                'sla_calendar_snapshot' => $calendar, 'sla_started_at' => $start,
                'sla_due_at' => $this->calculator->calculate($start, $rule->resolution_minutes, $calendar),
                'sla_warning_at' => $this->calculator->calculate($start, (int) ceil($rule->resolution_minutes * $rule->warning_percent / 100), $calendar),
                'source_system' => $data['source_system'] ?? null, 'external_ticket_id' => $data['external_ticket_id'] ?? null,
            ]);

            // 5. Generate formatted unique ticket number (e.g. TKT-2026-000001)
            $ticket->update(['ticket_number' => sprintf('TKT-%s-%06d', $start->format('Y'), $ticket->id)]);

            // 6. Record creation activity in audit log
            $this->audit->execute($ticket, $actor, 'ticket.created', [], ['ticket_number' => $ticket->ticket_number]);

            // 7. Trigger auto-assignment if enabled in configuration
            if (config('support.auto_assign')) {
                $this->assignment->autoAssign($ticket, $actor);
            }

            return $ticket;
        }, 3);
    }
}
