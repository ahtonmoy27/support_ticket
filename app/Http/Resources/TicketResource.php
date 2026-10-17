<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'ticket_number' => $this->ticket_number, 'subject' => $this->subject, 'description' => $this->description,
            'priority' => $this->priority->value, 'status' => $this->status->value, 'customer_id' => $this->customer_id,
            'assigned_agent_id' => $this->assigned_agent_id, 'category_id' => $this->category_id,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator->only(['id', 'name'])),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer->only(['id', 'name'])),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn () => $this->assignedAgent?->only(['id', 'name'])),
            'category' => $this->whenLoaded('category', fn () => $this->category->only(['id', 'name'])),
            'sla_started_at' => $this->sla_started_at, 'sla_warning_at' => $this->sla_warning_at, 'sla_due_at' => $this->sla_due_at,
            'sla_timezone' => $this->sla_calendar_snapshot['timezone'] ?? 'UTC',
            'sla_breached_at' => $this->sla_breached_at, 'resolved_at' => $this->resolved_at, 'closed_at' => $this->closed_at,
            'sla_state' => ! $this->sla_due_at
                ? 'within'
                : ($this->resolved_at
                    ? ($this->resolved_at->lessThan($this->sla_due_at) && ! $this->sla_breached_at ? 'met' : 'breached')
                    : ($this->sla_due_at->lessThanOrEqualTo(now()) ? 'breached' : ($this->sla_warning_at?->lessThanOrEqualTo(now()) ? 'warning' : 'within'))),
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
