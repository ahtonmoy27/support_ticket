<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->whenHas('role'),
            'is_active' => $this->is_active,
            'is_available' => $this->whenHas('is_available'),
            'email_verified_at' => $this->whenHas('email_verified_at'),
            'created_at' => $this->created_at,
            'updated_at' => $this->whenHas('updated_at'),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
