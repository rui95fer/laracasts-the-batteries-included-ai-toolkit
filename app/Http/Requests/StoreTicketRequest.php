<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\TicketDepartment;
use App\TicketPriority;
use App\TicketSentiment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Ticket::class);
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
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'department' => ['required', Rule::enum(TicketDepartment::class)],
            'sentiment' => ['required', Rule::enum(TicketSentiment::class)],
            'initial_message' => ['required', 'string', 'max:10000'],
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
