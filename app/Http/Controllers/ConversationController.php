<?php

namespace App\Http\Controllers;

use App\Domain\Tickets\Actions\AddTicketMessage;
use App\Http\Requests\MessageRequest;
use App\Http\Resources\TicketMessageResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return TicketMessageResource::collection($query->orderBy('id')->paginate(25))->response();
    }

    public function store(MessageRequest $request, Ticket $ticket, AddTicketMessage $action): JsonResponse
    {
        $message = $action->execute($ticket->id, $request->user(), $request->validated());

        return response()->json(['data' => new TicketMessageResource($message)], 201);
    }
}
