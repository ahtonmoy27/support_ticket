<?php

namespace App\Domain\Tickets\Services;

use App\Domain\Tickets\Actions\ImportTicketRow;
use App\Models\TicketImport;
use Illuminate\Validation\ValidationException;

class TicketImportProcessor
{
    public function __construct(private TicketCsvReader $reader, private ImportTicketRow $rows) {}

    public function execute(int $importId): void
    {
        $import = TicketImport::findOrFail($importId);
        if (in_array($import->status, ['completed', 'failed'], true)) {
            return;
        }
        $import->update(['status' => 'processing', 'error' => null]);
        $rowNumber = 0;
        try {
            foreach ($this->reader->read($import->storage_key) as $rowNumber => $row) {
                if ($import->rows()->where('row_number', $rowNumber)->exists()) {
                    continue;
                }
                try {
                    if (count($row['headers']) !== count($row['values'])) {
                        throw ValidationException::withMessages(['row' => 'Column count does not match header.']);
                    }
                    $this->rows->execute($importId, $rowNumber, array_combine($row['headers'], $row['values']));
                } catch (ValidationException $exception) {
                    $this->rows->recordError($importId, $rowNumber, $exception->errors());
                }
            }
            $import->refresh()->update(['status' => 'completed', 'total_rows' => $rowNumber]);
        } catch (\InvalidArgumentException $exception) {
            $import->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        }
    }
}
