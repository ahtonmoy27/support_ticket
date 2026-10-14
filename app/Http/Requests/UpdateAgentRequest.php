<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administer-support') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ];
    }
}
