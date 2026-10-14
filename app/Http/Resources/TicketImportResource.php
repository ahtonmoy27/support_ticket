<?php

namespace App\Http\Resources;

use App\Models\TicketImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketImport */
class TicketImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'filename' => $this->filename,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'failed_rows' => $this->failed_rows,
            'skipped_rows' => $this->skipped_rows,
            'error' => $this->error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
