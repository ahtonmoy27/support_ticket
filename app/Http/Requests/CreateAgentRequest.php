<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administer-support') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:12'],
            'category_ids' => ['required', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ];
    }
}
