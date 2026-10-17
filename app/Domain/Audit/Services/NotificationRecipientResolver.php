<?php

namespace App\Domain\Audit\Services;

use App\Models\Ticket;
use App\Models\User;

class NotificationRecipientResolver
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<int>
     */
    public function resolve(Ticket $ticket, ?User $actor, string $action, array $old = [], array $new = [], string $visibility = 'public'): array
    {
        $recipients = [];

        switch ($action) {
            case 'ticket.created':
                // Rule A: Customer confirmation
                if ($ticket->customer_id) {
                    $recipients[] = $ticket->customer_id;
                }
                // Rule A: Admin if enabled
                if (config('support.notifications.admin.ticket_created', true)) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                break;

            case 'ticket.assigned':
            case 'ticket.claimed':
                $assignedAgentId = $new['assigned_agent_id'] ?? $ticket->assigned_agent_id;
                // Rule B: Assigned Agent
                if ($assignedAgentId) {
                    $recipients[] = (int) $assignedAgentId;
                }
                // Rule B: Customer if enabled
                if (config('support.notifications.customer.ticket_assigned', true) && $ticket->customer_id) {
                    $recipients[] = $ticket->customer_id;
                }
                // Rule B: Admin if enabled
                if (config('support.notifications.admin.ticket_assigned', false)) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                break;

            case 'ticket.reassigned':
                $newAgentId = $new['assigned_agent_id'] ?? $ticket->assigned_agent_id;
                $oldAgentId = $old['assigned_agent_id'] ?? null;
                // Rule I: New Support Agent
                if ($newAgentId) {
                    $recipients[] = (int) $newAgentId;
                }
                // Rule I: Previous Support Agent
                if ($oldAgentId && (int) $oldAgentId !== (int) $newAgentId) {
                    $recipients[] = (int) $oldAgentId;
                }
                // Rule I: Customer if enabled
                if (config('support.notifications.customer.ticket_assigned', true) && $ticket->customer_id) {
                    $recipients[] = $ticket->customer_id;
                }
                // Rule I: Admin if enabled
                if (config('support.notifications.admin.ticket_assigned', false)) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                break;

            case 'ticket.replied':
                // Check author / actor role
                if ($actor?->role === 'customer') {
                    // Rule C: Customer Replied
                    // Notify Assigned Support Agent
                    if ($ticket->assigned_agent_id) {
                        $recipients[] = $ticket->assigned_agent_id;
                    }
                    // Admin if configured
                    if (config('support.notifications.admin.customer_replied', false)) {
                        $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                    }
                } else {
                    // Rule D: Agent Replied (or Admin Replied)
                    // Notify Customer who owns ticket
                    if ($ticket->customer_id) {
                        $recipients[] = $ticket->customer_id;
                    }
                    // If actor is not the assigned agent, notify assigned agent
                    if ($ticket->assigned_agent_id && $ticket->assigned_agent_id !== $actor?->id) {
                        $recipients[] = $ticket->assigned_agent_id;
                    }
                    // Admin if configured
                    if (config('support.notifications.admin.agent_replied', false)) {
                        $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                    }
                }
                break;

            case 'ticket.internal_note':
                // Rule E: Internal Agent Note
                // Customer MUST NEVER receive an internal note notification
                if ($ticket->assigned_agent_id && $ticket->assigned_agent_id !== $actor?->id) {
                    if (config('support.notifications.agent.internal_note', true)) {
                        $recipients[] = $ticket->assigned_agent_id;
                    }
                }
                // Admin if configured
                if (config('support.notifications.admin.internal_note', true)) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                break;

            case 'ticket.resolved':
            case 'ticket.closed':
                // Rule F: Status Changed
                // Notify Customer who owns ticket
                if ($ticket->customer_id) {
                    $recipients[] = $ticket->customer_id;
                }
                // Notify Assigned Agent if they did not perform the action themselves
                if ($ticket->assigned_agent_id && $ticket->assigned_agent_id !== $actor?->id) {
                    $recipients[] = $ticket->assigned_agent_id;
                }
                // Admin if configured
                if (config('support.notifications.admin.status_changed', false)) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                break;

            case 'sla.warning':
                // Rule G: SLA Approaching
                if ($ticket->assigned_agent_id) {
                    $recipients[] = $ticket->assigned_agent_id;
                }
                if (config('support.notifications.admin.sla_warning', true)) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                if (config('support.notifications.customer.sla_warning', false) && $ticket->customer_id) {
                    $recipients[] = $ticket->customer_id;
                }
                break;

            case 'sla.breach':
                // Rule H: SLA Breached
                if ($ticket->assigned_agent_id) {
                    $recipients[] = $ticket->assigned_agent_id;
                }
                if (config('support.notifications.admin.sla_breach', true)) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                if (config('support.notifications.customer.sla_breach', false) && $ticket->customer_id) {
                    $recipients[] = $ticket->customer_id;
                }
                break;

            default:
                // Legacy fallback: public -> customer, assigned agent, SLA admins
                if ($visibility !== 'internal' && $ticket->customer_id) {
                    $recipients[] = $ticket->customer_id;
                }
                if ($ticket->assigned_agent_id) {
                    $recipients[] = $ticket->assigned_agent_id;
                }
                if (str_starts_with($action, 'sla.')) {
                    $recipients = array_merge($recipients, $this->getActiveAdminIds($actor));
                }
                break;
        }

        // Exclude actor unless it is ticket.created confirmation for the customer
        $filtered = array_filter(array_unique($recipients), function (int $id) use ($actor, $action, $ticket): bool {
            if ($action === 'ticket.created' && $id === $ticket->customer_id) {
                return true;
            }

            return $id !== $actor?->id;
        });

        // Security check: if visibility is internal, ALWAYS remove customer
        if ($visibility === 'internal' || $action === 'ticket.internal_note') {
            $filtered = array_filter($filtered, fn (int $id): bool => $id !== $ticket->customer_id);
        }

        // Return array of active user IDs
        if (empty($filtered)) {
            return [];
        }

        return User::query()->whereIn('id', $filtered)->where('is_active', true)->pluck('id')->all();
    }

    /**
     * @return list<int>
     */
    private function getActiveAdminIds(?User $actor): array
    {
        return User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->when($actor !== null, fn ($q) => $q->where('id', '!=', $actor->id))
            ->pluck('id')
            ->all();
    }
}
