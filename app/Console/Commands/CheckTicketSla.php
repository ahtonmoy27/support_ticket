<?php

namespace App\Console\Commands;

use App\Domain\SLA\Services\SlaEvaluationService;
use Illuminate\Console\Command;

class CheckTicketSla extends Command
{
    protected $signature = 'tickets:check-sla';

    protected $description = 'Record due SLA warnings and breaches once per ticket cycle';

    public function handle(SlaEvaluationService $service): int
    {
        $this->info($service->execute().' escalation(s) recorded.');

        return self::SUCCESS;
    }
}
