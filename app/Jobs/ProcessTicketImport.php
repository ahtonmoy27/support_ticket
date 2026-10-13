<?php

namespace App\Jobs;

use App\Domain\Tickets\Actions\CreateTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProcessTicketImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $importId)
    {
        $this->onQueue('imports');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('ticket-import-'.$this->importId))->releaseAfter(30)->expireAfter(1000)];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CreateTicket $create): void
    {
        $import = TicketImport::findOrFail($this->importId);
        if (in_array($import->status, ['completed', 'failed'], true)) {
            return;
        }
        $import->update(['status' => 'processing', 'error' => null]);
        $stream = Storage::disk('private')->readStream($import->storage_key);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Cannot read import file.');
        }
        try {
            $headers = fgetcsv($stream, 131072, ',', '"', '');
            if (! is_array($headers)) {
                throw new \InvalidArgumentException('CSV is empty.');
            }
            $headers = array_map(fn ($header) => trim((string) $header), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            if (count($headers) !== count(array_unique($headers)) || array_diff(['customer_email', 'category', 'subject', 'description', 'priority'], $headers)) {
                throw new \InvalidArgumentException('Required unique CSV headers: customer_email,category,subject,description,priority.');
            }
            $rowNumber = 0;
            while (($values = fgetcsv($stream, 131072, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                $rowNumber++;
                if ($rowNumber > config('support.import_max_rows')) {
                    throw new \InvalidArgumentException('CSV exceeds the configured row limit.');
                }
                if ($import->rows()->where('row_number', $rowNumber)->exists()) {
                    continue;
                }
                try {
                    if (count($values) !== count($headers)) {
                        throw ValidationException::withMessages(['row' => 'Column count does not match header.']);
                    }
                    $row = array_combine($headers, $values);
                    $data = Validator::make($row, [
                        'customer_email' => ['required', 'email'], 'category' => ['required', 'string', 'max:255'],
                        'subject' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:50000'],
                        'priority' => ['required', 'in:low,medium,high,critical'],
                        'source_system' => ['nullable', 'required_with:external_ticket_id', 'string', 'max:100'],
                        'external_ticket_id' => ['nullable', 'required_with:source_system', 'string', 'max:100'],
                    ])->validate();
                    $customer = User::query()->where('email', $data['customer_email'])->where('role', 'customer')->where('is_active', true)->first();
                    $category = Category::query()->where('name', $data['category'])->where('is_active', true)->first();
                    if (! $customer || ! $category) {
                        throw ValidationException::withMessages(['row' => 'Active customer or category not found.']);
                    }
                    $data['customer_id'] = $customer->id;
                    $data['category_id'] = $category->id;
                    $data['source_system'] = ($data['source_system'] ?? '') !== '' ? $data['source_system'] : null;
                    $data['external_ticket_id'] = ($data['external_ticket_id'] ?? '') !== '' ? $data['external_ticket_id'] : null;
                    DB::transaction(function () use ($import, $rowNumber, $data, $create) {
                        DB::table('support_locks')->where('name', 'assignment')->lockForUpdate()->first();
                        $locked = TicketImport::query()->lockForUpdate()->findOrFail($import->id);
                        if ($locked->rows()->where('row_number', $rowNumber)->exists()) {
                            return;
                        }
                        $duplicate = $data['source_system'] ? Ticket::query()->where('source_system', $data['source_system'])->where('external_ticket_id', $data['external_ticket_id'])->first() : null;
                        $ticket = $duplicate ?? $create->execute($locked->creator, $data);
                        $locked->rows()->create(['row_number' => $rowNumber, 'status' => $duplicate ? 'skipped' : 'success', 'ticket_id' => $ticket->id]);
                        $locked->increment('processed_rows');
                        if ($duplicate) {
                            $locked->increment('skipped_rows');
                        }
                        $locked->update(['total_rows' => max($locked->total_rows, $rowNumber)]);
                    }, 3);
                } catch (ValidationException $exception) {
                    $this->recordError($rowNumber, $exception->errors());
                }
            }
            $import->refresh()->update(['status' => 'completed', 'total_rows' => $rowNumber]);
        } catch (\InvalidArgumentException $exception) {
            $import->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        } finally {
            fclose($stream);
        }
    }

    private function recordError(int $row, array $errors): void
    {
        DB::transaction(function () use ($row, $errors) {
            $import = TicketImport::query()->lockForUpdate()->findOrFail($this->importId);
            if ($import->rows()->where('row_number', $row)->exists()) {
                return;
            }
            $import->rows()->create(['row_number' => $row, 'status' => 'failed', 'errors' => $errors]);
            $import->increment('processed_rows');
            $import->increment('failed_rows');
            $import->update(['total_rows' => max($import->total_rows, $row)]);
        }, 3);
    }

    public function failed(?\Throwable $exception): void
    {
        TicketImport::whereKey($this->importId)->update(['status' => 'failed', 'error' => 'Processing failed after retries. Check the failed job log.']);
    }
}
