<?php
namespace App\Policies;
use App\Models\Ticket;
use App\Models\User;
class TicketPolicy {
    public function viewAny(User $user): bool { return $user->is_active; }
    public function create(User $user): bool { return $user->is_active; }
    public function view(User $user, Ticket $ticket): bool {
        return $user->is_active && ($user->isAdmin() || ($user->isAgent()
            ? $ticket->assigned_agent_id === $user->id || ($ticket->assigned_agent_id === null && $ticket->status->isActive() && $user->categories()->whereKey($ticket->category_id)->exists())
            : $ticket->customer_id === $user->id));
    }
    public function update(User $user, Ticket $ticket): bool {
        return $ticket->status->isActive() && $this->reply($user, $ticket);
    }
    public function claim(User $user, Ticket $ticket): bool {
        return $user->is_active && ($user->isAdmin() || ($user->isAgent() && $user->is_available && $user->categories()->whereKey($ticket->category_id)->exists()));
    }
    public function assign(User $user, Ticket $ticket): bool { return $user->is_active && $user->isAdmin(); }
    public function reply(User $user, Ticket $ticket): bool {
        return $user->is_active && ($user->isAdmin() || ($user->isAgent() ? $ticket->assigned_agent_id === $user->id : $ticket->customer_id === $user->id));
    }
    public function addInternalNote(User $user, Ticket $ticket): bool {
        return $user->role !== 'customer' && $this->reply($user, $ticket);
    }
    public function resolve(User $user, Ticket $ticket): bool { return $this->addInternalNote($user, $ticket); }
    public function close(User $user, Ticket $ticket): bool {
        return $user->is_active && ($user->isAdmin() || ($user->role === 'customer' && $ticket->customer_id === $user->id));
    }
}