<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartTicketImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administer-support') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:51200'],
        ];
    }
}
