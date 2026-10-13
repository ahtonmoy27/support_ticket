<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\RecordActivity;
use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);
        $query = $ticket->attachments();
        if ($request->user()->role === 'customer') {
            $query->where(fn ($q) => $q->whereNull('ticket_message_id')->orWhereHas('message', fn ($m) => $m->where('visibility', 'public')));
        }

        return response()->json($query->orderByDesc('id')->paginate(25));
    }

    public function store(Request $request, Ticket $ticket, RecordActivity $audit): JsonResponse
    {
        Gate::authorize('reply', $ticket);
        $data = $request->validate(['file' => ['required', File::types(['pdf', 'png', 'jpg', 'jpeg', 'txt', 'docx'])->max('10mb'), 'extensions:pdf,png,jpg,jpeg,txt,docx'], 'ticket_message_id' => ['nullable', 'integer']]);
        $file = $request->file('file');
        $disk = config('support.attachment_disk');
        $path = $file->store('attachments', $disk);
        try {
            $attachment = DB::transaction(function () use ($request, $ticket, $data, $file, $disk, $path, $audit) {
                $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                Gate::authorize('reply', $locked);
                abort_if(! $locked->status->isActive(), 409, 'Ticket no longer accepts attachments.');
                $message = isset($data['ticket_message_id']) ? $locked->messages()->findOrFail($data['ticket_message_id']) : null;
                if ($message?->visibility === 'internal') {
                    Gate::authorize('addInternalNote', $locked);
                }
                $attachment = $locked->attachments()->create(['ticket_message_id' => $message?->id, 'uploaded_by' => $request->user()->id,
                    'original_name' => basename($file->getClientOriginalName()), 'storage_key' => $path, 'disk' => $disk,
                    'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'scan_status' => 'available']);
                $audit->execute($locked, $request->user(), 'ticket.attachment_added', [], ['attachment_id' => $attachment->id], $message?->visibility ?? 'public', false);

                return $attachment;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $attachment], 201);
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment->ticket);
        if ($attachment->message?->visibility === 'internal') {
            Gate::authorize('addInternalNote', $attachment->ticket);
        }
        abort_unless($attachment->scan_status === 'available', 409, 'Attachment unavailable.');

        return Storage::disk($attachment->disk)->download($attachment->storage_key, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
