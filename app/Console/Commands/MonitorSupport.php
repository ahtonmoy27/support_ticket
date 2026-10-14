<?php

namespace App\Console\Commands;

use App\Domain\Tickets\Services\SupportHealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorSupport extends Command
{
    protected $signature = 'tickets:monitor {--json : Output machine-readable health metrics}';

    protected $description = 'Check support queues, outbox, imports and scheduler heartbeats';

    public function handle(SupportHealthCheck $health): int
    {
        $report = $health->execute();
        if (! $report['healthy'] && Cache::add('support.monitor.alert.'.sha1(implode('|', $report['issues'])), true, config('support.monitor.alert_cooldown_seconds'))) {
            Log::warning('Support operations unhealthy.', $report);
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line($report['healthy'] ? 'Support operations healthy.' : 'Support issues: '.implode(', ', $report['issues']));
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
