<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\TicketDepartment;
use App\TicketPriority;
use App\TicketSentiment;
use App\TicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:150'],
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email', 'max:255'],
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'department' => ['required', Rule::enum(TicketDepartment::class)],
            'sentiment' => ['required', Rule::enum(TicketSentiment::class)],
            'tags' => ['array', 'max:10'],
            'tags.*' => ['string', 'max:40', 'distinct:ignore_case'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $tags = collect($this->input('tags', []))
            ->filter(fn (mixed $tag): bool => is_string($tag))
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->values()
            ->all();

        $this->merge(['tags' => $tags]);
    }
}
