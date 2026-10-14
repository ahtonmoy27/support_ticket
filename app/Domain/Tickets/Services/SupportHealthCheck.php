<?php

namespace App\Domain\Tickets\Services;

use App\Models\OutboxEvent;
use App\Models\TicketImport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class SupportHealthCheck
{
    /** @return array{healthy:bool,checked_at:string,metrics:array<string,int|null>,issues:list<string>} */
    public function execute(): array
    {
        $metrics = [
            'pending_outbox' => OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'stale_outbox' => OutboxEvent::query()->whereNull('dispatched_at')->where('created_at', '<=', now()->subSeconds(config('support.monitor.outbox_max_age_seconds')))->count(),
            'stuck_imports' => TicketImport::query()->whereIn('status', ['pending', 'processing'])->where('updated_at', '<=', now()->subSeconds(max(1200, (int) config('support.import_stale_seconds'))))->count(),
            'failed_imports' => TicketImport::query()->where('status', 'failed')->count(),
            'failed_jobs' => DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))
                ->where(fn ($query) => $query->where('payload', 'like', '%ProcessTicketImport%')->orWhere('payload', 'like', '%TicketChanged%'))->count(),
        ];
        $issues = [];
        foreach (['stale_outbox', 'stuck_imports', 'failed_imports', 'failed_jobs'] as $metric) {
            if ($metrics[$metric] > 0) {
                $issues[] = $metric;
            }
        }
        foreach (['sla', 'outbox'] as $task) {
            $lastRun = Cache::get('support.heartbeat.'.$task);
            $metrics[$task.'_heartbeat_age_seconds'] = $lastRun === null ? null : max(0, now()->timestamp - (int) $lastRun);
            if ($lastRun === null || $metrics[$task.'_heartbeat_age_seconds'] > config('support.monitor.heartbeat_max_age_seconds')) {
                $issues[] = $task.'_scheduler_stale';
            }
        }
        foreach (['imports', 'notifications'] as $queueName) {
            $queue = Queue::connection();
            $metrics[$queueName.'_queued_jobs'] = $queue->size($queueName);
            $oldest = $queue->creationTimeOfOldestPendingJob($queueName);
            $metrics[$queueName.'_oldest_job_age_seconds'] = $oldest === null ? null : max(0, now()->timestamp - $oldest);
            if ($metrics[$queueName.'_queued_jobs'] > config('support.monitor.queue_max_jobs') || ($metrics[$queueName.'_oldest_job_age_seconds'] ?? 0) > config('support.monitor.queue_max_age_seconds')) {
                $issues[] = $queueName.'_queue_backlog';
            }
        }

        return ['healthy' => $issues === [], 'checked_at' => now()->toIso8601String(), 'metrics' => $metrics, 'issues' => $issues];
    }
}
