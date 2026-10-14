<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administer-support') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories')->ignore($this->route('category'))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
