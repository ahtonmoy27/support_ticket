<?php

namespace Tests\Support;

use App\Domain\Tickets\Actions\ImportTicketRow;
use App\Domain\Tickets\Services\TicketCsvReader;
use App\Domain\Tickets\Services\TicketImportProcessor;
use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;

class CrashOnceImport extends ProcessTicketImport
{
    public function handle(TicketImportProcessor $processor): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'support_ticket_sla_test') {
            throw new \RuntimeException('Crash fixture requires the isolated test database.');
        }
        $import = TicketImport::findOrFail($this->importId);
        if (! $import->rows()->exists()) {
            $import->update(['status' => 'processing']);
            foreach (app(TicketCsvReader::class)->read($import->storage_key) as $rowNumber => $row) {
                app(ImportTicketRow::class)->execute($import->id, $rowNumber, array_combine($row['headers'], $row['values']));
                exit(17);
            }
        }
        parent::handle($processor);
    }
}
