<?php
namespace App\Http\Controllers;
use App\Models\Ticket;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Actions\UpdateTicket;
use App\Domain\Tickets\Actions\ChangeTicketStatus;
use App\Domain\Tickets\Queries\TicketSearchQuery;
use App\Domain\Assignment\Actions\AssignTicket;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Http\Requests\CreateTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Requests\SearchTicketsRequest;
use App\Http\Resources\TicketResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
class TicketController extends Controller {
    public function index(SearchTicketsRequest $request, TicketSearchQuery $query): AnonymousResourceCollection {
        return TicketResource::collection($query->execute($request->user(),$request->validated())->with(['customer','assignedAgent','category'])->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page',25))->withQueryString());
    }
    public function store(CreateTicketRequest $request, CreateTicket $action): JsonResponse {
        return (new TicketResource($action->execute($request->user(),$request->validated())))->response()->setStatusCode(201);
    }
    public function show(Request $request, Ticket $ticket): TicketResource {
        Gate::authorize('view',$ticket);
        return new TicketResource($ticket->load(['customer','assignedAgent','category']));
    }
    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $action): TicketResource { return new TicketResource($action->execute($ticket->id,$request->user(),$request->validated())); }
    public function claim(Request $request, Ticket $ticket, AssignTicket $action): TicketResource { return new TicketResource($action->execute($ticket->id,$request->user(),claim:true)); }
    public function assign(Request $request, Ticket $ticket, AssignTicket $action): TicketResource {
        Gate::authorize('assign',$ticket);
        $data = $request->validate(['assigned_agent_id'=>['required','integer','exists:users,id']]);
        return new TicketResource($action->execute($ticket->id,$request->user(),$data['assigned_agent_id']));
    }
    public function status(Request $request, Ticket $ticket, ChangeTicketStatus $action): TicketResource {
        $data = $request->validate(['status'=>['required',Rule::enum(TicketStatus::class)]]);
        return new TicketResource($action->execute($ticket->id,$request->user(),TicketStatus::from($data['status'])));
    }
    public function resolve(Request $request, Ticket $ticket, ChangeTicketStatus $action): TicketResource { return new TicketResource($action->execute($ticket->id,$request->user(),TicketStatus::Resolved)); }
    public function close(Request $request, Ticket $ticket, ChangeTicketStatus $action): TicketResource { return new TicketResource($action->execute($ticket->id,$request->user(),TicketStatus::Closed)); }
    public function activities(Request $request, Ticket $ticket): JsonResponse {
        Gate::authorize('view',$ticket);
        $query = $ticket->activities();
        if ($request->user()->role === 'customer') { $query->where('visibility','public'); }
        return response()->json($query->orderByDesc('id')->paginate(25));
    }
}