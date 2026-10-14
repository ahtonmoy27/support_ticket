<?php

namespace App\Http\Controllers;

use App\Domain\Tickets\Actions\UploadTicketAttachment;
use App\Http\Requests\UploadAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

        return AttachmentResource::collection($query->orderByDesc('id')->paginate(25))->response();
    }

    public function store(UploadAttachmentRequest $request, Ticket $ticket, UploadTicketAttachment $action): JsonResponse
    {
        $data = $request->validated();
        $attachment = $action->execute($ticket->id, $request->user(), $request->file('file'), isset($data['ticket_message_id']) ? (int) $data['ticket_message_id'] : null);

        return response()->json(['data' => new AttachmentResource($attachment)], 201);
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
