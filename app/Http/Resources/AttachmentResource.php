<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attachment */
class AttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'ticket_message_id' => $this->ticket_message_id,
            'uploaded_by' => $this->uploaded_by,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'scan_status' => $this->scan_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
