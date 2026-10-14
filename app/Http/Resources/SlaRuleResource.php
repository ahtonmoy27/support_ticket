<?php

namespace App\Http\Resources;

use App\Models\SlaRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SlaRule */
class SlaRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sla_policy_id' => $this->sla_policy_id,
            'priority' => $this->priority,
            'resolution_minutes' => $this->resolution_minutes,
            'warning_percent' => $this->warning_percent,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
