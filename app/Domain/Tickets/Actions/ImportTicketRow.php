<?php

namespace App\Domain\Tickets\Actions;

use App\Domain\Tickets\Enums\TicketPriority;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportTicketRow
{
    public function __construct(private CreateTicket $create) {}

    /** @param array<string, string|null> $row */
    public function execute(int $importId, int $rowNumber, array $row): void
    {
        $data = Validator::make($row, [
            'customer_email' => ['required', 'email'], 'category' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:50000'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
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
        try {
            $this->persist($importId, $rowNumber, $data);
        } catch (UniqueConstraintViolationException $exception) {
            if ($data['source_system'] === null) {
                throw $exception;
            }
            $this->persist($importId, $rowNumber, $data);
        }
    }

    /** @param array<string, mixed> $data */
    private function persist(int $importId, int $rowNumber, array $data): void
    {
        DB::transaction(function () use ($importId, $rowNumber, $data): void {
            $import = TicketImport::query()->lockForUpdate()->findOrFail($importId);
            if ($import->rows()->where('row_number', $rowNumber)->exists()) {
                return;
            }
            $duplicate = $data['source_system'] !== null
                ? Ticket::query()->where('source_system', $data['source_system'])->where('external_ticket_id', $data['external_ticket_id'])->first()
                : null;
            $ticket = $duplicate ?? $this->create->execute($import->creator, $data);
            $import->rows()->create(['row_number' => $rowNumber, 'status' => $duplicate ? 'skipped' : 'success', 'ticket_id' => $ticket->id]);
            $import->increment('processed_rows');
            if ($duplicate) {
                $import->increment('skipped_rows');
            }
            $import->update(['total_rows' => max($import->total_rows, $rowNumber)]);
        }, 3);
    }

    /** @param array<string, list<string>> $errors */
    public function recordError(int $importId, int $rowNumber, array $errors): void
    {
        DB::transaction(function () use ($importId, $rowNumber, $errors): void {
            $import = TicketImport::query()->lockForUpdate()->findOrFail($importId);
            if ($import->rows()->where('row_number', $rowNumber)->exists()) {
                return;
            }
            $import->rows()->create(['row_number' => $rowNumber, 'status' => 'failed', 'errors' => $errors]);
            $import->increment('processed_rows');
            $import->increment('failed_rows');
            $import->update(['total_rows' => max($import->total_rows, $rowNumber)]);
        }, 3);
    }
}
