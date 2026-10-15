<?php

namespace App\Http\Resources;

use App\Models\TicketActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketActivity */
class TicketActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'actor_id' => $this->actor_id,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor?->only(['id', 'name'])),
            'action' => $this->action,
            'visibility' => $this->visibility,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'created_at' => $this->created_at,
        ];
    }
}
