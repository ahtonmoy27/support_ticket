<?php

namespace App\Http\Resources;

use App\Models\BusinessCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BusinessCalendar */
class BusinessCalendarResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'hours' => $this->hours,
            'exceptions' => $this->exceptions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
