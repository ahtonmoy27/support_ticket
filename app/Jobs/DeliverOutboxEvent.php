<?php

namespace App\Jobs;

use App\Domain\Audit\Services\DispatchOutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId)
    {
        $this->onQueue(config('support.notifications_queue'));
    }

    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function handle(DispatchOutboxEvent $dispatcher): void
    {
        $dispatcher->execute($this->eventId);
    }
}
