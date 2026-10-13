<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->input('visibility') === 'internal' ? 'addInternalNote' : 'reply', $this->route('ticket')) ?? false;
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:50000'], 'visibility' => ['required', Rule::in(['public', 'internal'])], 'author_id' => ['prohibited']];
    }
}
