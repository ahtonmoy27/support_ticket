<?php

namespace App\Http\Requests;

use App\Domain\Tickets\Enums\TicketPriority;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:50000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'customer_id' => [$this->user()->isAdmin() ? 'nullable' : 'prohibited', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('role', 'customer')],
            'assigned_agent_id' => ['prohibited'], 'status' => ['prohibited'], 'author_id' => ['prohibited'],
            'source_system' => ['nullable', 'required_with:external_ticket_id', 'string', 'max:100'],
            'external_ticket_id' => ['nullable', 'required_with:source_system', 'string', 'max:100', Rule::unique('tickets')->where('source_system', $this->input('source_system'))],
        ];
    }
}
