<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchOutbox extends Command
{
    protected $signature = 'tickets:dispatch-outbox';

    protected $description = 'Queue pending ticket notifications from the durable outbox';

    public function handle(DispatchOutboxEvent $dispatcher): int
    {
        OutboxEvent::query()->whereNull('dispatched_at')->select('id')->chunkById(100, function ($events) use ($dispatcher): void {
            foreach ($events as $candidate) {
                $dispatcher->execute($candidate->id);
            }
        });

        Cache::put('support.heartbeat.outbox', now()->timestamp, now()->addDay());

        return self::SUCCESS;
    }
}
