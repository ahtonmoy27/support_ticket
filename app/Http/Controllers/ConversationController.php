<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\RecordActivity;
use App\Http\Requests\MessageRequest;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);
        $query = $ticket->messages()->with('author:id,name');
        if ($request->user()->role === 'customer') {
            $query->where('visibility', 'public');
        }

        return response()->json($query->orderBy('id')->paginate(25));
    }

    public function store(MessageRequest $request, Ticket $ticket, RecordActivity $audit): JsonResponse
    {
        $message = DB::transaction(function () use ($request, $ticket, $audit) {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::authorize($request->input('visibility') === 'internal' ? 'addInternalNote' : 'reply', $locked);
            abort_if(! $locked->status->isActive(), 409, 'Resolved or closed tickets cannot receive messages.');
            $message = $locked->messages()->create($request->validated() + ['author_id' => $request->user()->id]);
            $audit->execute($locked, $request->user(), 'ticket.replied', [], ['message_id' => $message->id], $message->visibility);

            return $message;
        }, 3);

        return response()->json(['data' => $message], 201);
    }
}
