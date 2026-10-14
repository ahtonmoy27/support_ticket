<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reply', $this->route('ticket')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', File::types(['pdf', 'png', 'jpg', 'jpeg', 'txt', 'docx'])->max('10mb'), 'extensions:pdf,png,jpg,jpeg,txt,docx'],
            'ticket_message_id' => ['nullable', 'integer'],
        ];
    }
}
