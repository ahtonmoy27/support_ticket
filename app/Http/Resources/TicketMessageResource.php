<?php

namespace App\Http\Resources;

use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketMessage */
class TicketMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'author_id' => $this->author_id,
            'body' => $this->body,
            'visibility' => $this->visibility,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'author' => $this->whenLoaded('author', fn (): array => $this->author->only(['id', 'name'])),
        ];
    }
}
