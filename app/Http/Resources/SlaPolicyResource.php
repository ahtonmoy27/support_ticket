<?php

namespace App\Http\Resources;

use App\Models\SlaPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SlaPolicy */
class SlaPolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_calendar_id' => $this->business_calendar_id,
            'version' => $this->version,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'calendar' => new BusinessCalendarResource($this->whenLoaded('calendar')),
            'rules' => SlaRuleResource::collection($this->whenLoaded('rules')),
        ];
    }
}
