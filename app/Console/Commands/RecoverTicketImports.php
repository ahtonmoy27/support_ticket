<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverTicketImports extends Command
{
    protected $signature = 'tickets:recover-imports';

    protected $description = 'Requeue abandoned pending or processing imports without losing committed rows';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(max(1200, (int) config('support.import_stale_seconds')));
        $recovered = 0;
        TicketImport::query()->whereIn('status', ['pending', 'processing'])->where('updated_at', '<=', $cutoff)
            ->select('id')->chunkById(100, function ($imports) use ($cutoff, &$recovered): void {
                foreach ($imports as $candidate) {
                    $recovered += DB::transaction(function () use ($candidate, $cutoff): int {
                        $import = TicketImport::query()->lockForUpdate()->findOrFail($candidate->id);
                        if (! in_array($import->status, ['pending', 'processing'], true) || $import->updated_at->greaterThan($cutoff)) {
                            return 0;
                        }
                        $import->update(['status' => 'pending', 'updated_at' => now()]);
                        ProcessTicketImport::dispatch($import->id)->afterCommit();

                        return 1;
                    }, 3);
                }
            });
        $this->info($recovered.' import(s) requeued.');

        return self::SUCCESS;
    }
}
