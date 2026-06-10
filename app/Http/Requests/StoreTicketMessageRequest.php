<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTicketMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && $this->user()->can('update', $ticket);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TicketMessageType::class)],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ticket = $this->route('ticket');

                if (! $ticket instanceof Ticket) {
                    return;
                }

                if ($ticket->status === TicketStatus::Closed && $this->input('type') === TicketMessageType::AgentReply->value) {
                    $validator->errors()->add('type', 'Reopen the ticket before adding an agent reply.');
                }
            },
        ];
    }
}
