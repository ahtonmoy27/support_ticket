<?php

namespace App\Domain\Tickets\Actions;

use App\Domain\Audit\Services\RecordActivity;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class UploadTicketAttachment
{
    public function __construct(private RecordActivity $audit) {}

    public function execute(int $ticketId, User $actor, UploadedFile $file, ?int $messageId = null): Attachment
    {
        Gate::forUser($actor)->authorize('reply', Ticket::findOrFail($ticketId));
        $disk = config('support.attachment_disk');
        $path = $file->store('attachments', $disk);
        if ($path === false) {
            throw new \RuntimeException('Cannot store attachment.');
        }
        try {
            return DB::transaction(function () use ($ticketId, $actor, $file, $messageId, $disk, $path): Attachment {
                $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
                Gate::forUser($actor)->authorize('reply', $ticket);
                abort_if(! $ticket->status->isActive(), 409, 'Ticket no longer accepts attachments.');
                $message = $messageId !== null ? $ticket->messages()->findOrFail($messageId) : null;
                if ($message?->visibility === 'internal') {
                    Gate::forUser($actor)->authorize('addInternalNote', $ticket);
                }
                $attachment = $ticket->attachments()->create([
                    'ticket_message_id' => $message?->id, 'uploaded_by' => $actor->id,
                    'original_name' => basename($file->getClientOriginalName()), 'storage_key' => $path, 'disk' => $disk,
                    'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'scan_status' => 'available',
                ]);
                $this->audit->execute($ticket, $actor, 'ticket.attachment_added', [], ['attachment_id' => $attachment->id], $message?->visibility ?? 'public', false);

                return $attachment;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}
