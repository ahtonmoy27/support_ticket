<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Domain\Tickets\Enums\TicketPriority;
class SearchTicketsRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->is_active ?? false; }
    public function rules(): array { return [
        'q'=>['nullable','string','max:255'],'status'=>['nullable',Rule::enum(TicketStatus::class)],'priority'=>['nullable',Rule::enum(TicketPriority::class)],
        'category_id'=>['nullable','integer'],'customer_id'=>['nullable','integer'],'assigned_agent_id'=>['nullable','integer'],
        'unassigned'=>['nullable','boolean'],'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],
        'sla_state'=>['nullable',Rule::in(['within','warning','breached'])],'per_page'=>['nullable','integer','min:1','max:100'],
    ]; }
}