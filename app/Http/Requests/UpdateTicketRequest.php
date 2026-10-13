<?php

namespace App\Http\Requests;

use App\Domain\Tickets\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('ticket')) ?? false;
    }

    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'], 'description' => ['sometimes', 'required', 'string', 'max:50000'],
            'priority' => [$this->user()->role === 'customer' ? 'prohibited' : 'sometimes', 'required', Rule::enum(TicketPriority::class)],
            'customer_id' => ['prohibited'], 'assigned_agent_id' => ['prohibited'], 'category_id' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }
}
