<?php

namespace App\Jobs;

use App\Domain\Tickets\Services\TicketImportProcessor;
use App\Models\TicketImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessTicketImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $importId)
    {
        $this->onQueue(env('QUEUE_IMPORTS_NAME', 'imports'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('ticket-import-'.$this->importId))->releaseAfter(30)->expireAfter(1000)];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TicketImportProcessor $processor): void
    {
        $processor->execute($this->importId);
    }

    public function failed(?\Throwable $exception): void
    {
        TicketImport::whereKey($this->importId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error' => 'Processing failed after retries. Check the failed job log.']);
    }
}
