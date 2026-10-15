<?php

namespace Database\Seeders;

use App\Domain\Assignment\Actions\AssignTicket;
use App\Domain\SLA\Services\SlaEvaluationService;
use App\Domain\Tickets\Actions\AddTicketMessage;
use App\Domain\Tickets\Actions\ChangeTicketStatus;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

class SampleTicketSeeder extends Seeder
{
    public function run(): void
    {
        if (Ticket::count() > 0) {
            return;
        }

        $customer = User::where('role', 'customer')->first();
        $agent = User::where('role', 'agent')->first();
        $admin = User::where('role', 'admin')->first();

        if (! $customer || ! $agent || ! $admin) {
            return;
        }

        $techCategory = Category::where('name', 'Technical')->first() ?? Category::first();
        $billingCategory = Category::where('name', 'Billing')->first() ?? Category::first();
        $generalCategory = Category::where('name', 'General')->first() ?? Category::first();

        $createAction = app(CreateTicket::class);
        $assignAction = app(AssignTicket::class);
        $messageAction = app(AddTicketMessage::class);
        $statusAction = app(ChangeTicketStatus::class);
        $slaService = app(SlaEvaluationService::class);

        // Turn off auto-assign temporarily so we control assignment deterministically
        config(['support.auto_assign' => false]);

        // 1. Critical Ticket (Open & Unassigned, SLA Warning Approaching)
        $t1 = $createAction->execute($customer, [
            'subject' => 'Payment gateway timeout on checkout page',
            'description' => 'Customers are reporting a 504 Gateway Timeout error when attempting to pay with credit card on the checkout screen.',
            'category_id' => $techCategory->id,
            'priority' => 'critical',
        ]);
        $t1->update([
            'sla_warning_at' => now()->subMinutes(10),
            'sla_due_at' => now()->addMinutes(45),
        ]);

        // 2. High Priority Ticket (In Progress, SLA Breached & Escalated)
        $t2 = $createAction->execute($customer, [
            'subject' => 'Corporate account invoice discrepancy for March renewal',
            'description' => 'The annual renewal charge invoice shows $1,490 instead of the contracted rate of $1,190 with the early-bird discount.',
            'category_id' => $billingCategory->id,
            'priority' => 'high',
        ]);
        $assignAction->execute($t2->id, $admin, $agent->id);
        $t2->update([
            'sla_warning_at' => now()->subHours(5),
            'sla_due_at' => now()->subHour(),
        ]);
        $slaService->evaluateTicket($t2);

        // 3. Medium Priority Ticket (In Progress with Conversation & Internal Note)
        $t3 = $createAction->execute($customer, [
            'subject' => 'Webhook delivering duplicate events for ticket.updated',
            'description' => 'Our webhook receiver at https://api.client.com/webhooks receives 2 identical POST payloads within 500ms.',
            'category_id' => $techCategory->id,
            'priority' => 'medium',
        ]);
        $assignAction->execute($t3->id, $admin, $agent->id);
        $messageAction->execute($t3->id, $agent, [
            'body' => 'Hello! We received your report and are analyzing our webhook dispatch logs to verify queue retry intervals.',
            'visibility' => 'public',
        ]);
        $messageAction->execute($t3->id, $agent, [
            'body' => 'Staff Internal Note: Checked Redis queue configuration. The worker timeout might be triggering a retry before the external endpoint responds.',
            'visibility' => 'internal',
        ]);
        $messageAction->execute($t3->id, $customer, [
            'body' => 'Thank you for looking into this! We noticed it mostly happens during high volume peak hours.',
            'visibility' => 'public',
        ]);

        // 4. Low Priority Ticket (Waiting for Customer)
        $t4 = $createAction->execute($customer, [
            'subject' => 'Request to update registered business entity name and VAT number',
            'description' => 'We have recently completed our company name change. Please update our billing entity details.',
            'category_id' => $generalCategory->id,
            'priority' => 'low',
        ]);
        $assignAction->execute($t4->id, $admin, $agent->id);
        $messageAction->execute($t4->id, $agent, [
            'body' => 'Could you please upload a certified copy of your updated certificate of incorporation or tax certificate?',
            'visibility' => 'public',
        ]);
        $statusAction->execute($t4->id, $agent, TicketStatus::WaitingForCustomer);

        // 5. Medium Priority Ticket (Resolved)
        $t5 = $createAction->execute($customer, [
            'subject' => 'Password reset token expired too quickly',
            'description' => 'Reset links sent via email were timing out in less than 5 minutes.',
            'category_id' => $techCategory->id,
            'priority' => 'medium',
        ]);
        $assignAction->execute($t5->id, $admin, $agent->id);
        $messageAction->execute($t5->id, $agent, [
            'body' => 'We have extended the password reset token expiration window to 60 minutes. Please test and confirm.',
            'visibility' => 'public',
        ]);
        $statusAction->execute($t5->id, $agent, TicketStatus::Resolved);

        // 6. Low Priority Ticket (Closed)
        $t6 = $createAction->execute($customer, [
            'subject' => 'General inquiry regarding weekend emergency support coverage',
            'description' => 'Does standard SLA apply on national holidays or are emergency tickets routed to an on-call engineer?',
            'category_id' => $generalCategory->id,
            'priority' => 'low',
        ]);
        $assignAction->execute($t6->id, $admin, $agent->id);
        $statusAction->execute($t6->id, $agent, TicketStatus::Resolved);
        $statusAction->execute($t6->id, $agent, TicketStatus::Closed);

        if ($this->command) {
            $this->command->info('Sample tickets seeded (Open, In Progress, Waiting for Customer, Resolved, Closed, SLA Breached & Approaching).');
        }
    }
}

