<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryTicketImport extends Command
{
    protected $signature = 'tickets:retry-import {import : The failed import ID}';

    protected $description = 'Resume a failed import, preserving successfully committed rows';

    public function handle(): int
    {
        $retried = DB::transaction(function (): bool {
            $import = TicketImport::query()->lockForUpdate()->find($this->argument('import'));
            if (! $import || $import->status !== 'failed') {
                return false;
            }
            $import->update(['status' => 'pending', 'error' => null]);
            ProcessTicketImport::dispatch($import->id)->afterCommit();

            return true;
        }, 3);
        if (! $retried) {
            $this->error('Only an existing failed import can be retried.');

            return self::FAILURE;
        }
        $this->info('Import queued for retry.');

        return self::SUCCESS;
    }
}
