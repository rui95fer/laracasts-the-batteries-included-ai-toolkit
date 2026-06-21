<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class TicketFactsTool implements Tool
{
    public function __construct(
        public readonly int $ticketId,
        public readonly ?User $user = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Fetches the current key facts about the ticket: number, subject, status, priority, '
            .'department, sentiment, customer, tags, and timestamps.';
    }

    public function name(): string
    {
        return 'ticket_facts';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->user ?? auth()->user();

        if (! $user) {
            return 'unauthorized';
        }

        $ticket = Ticket::query()
            ->with('tags')
            ->find($this->ticketId);

        if (! $ticket) {
            return 'unauthorized';
        }

        if (! Gate::forUser($user)->allows('view', $ticket)) {
            return 'unauthorized';
        }

        return json_encode([
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority?->value,
            'department' => $ticket->department?->value,
            'sentiment' => $ticket->sentiment?->value,
            'customer_name' => $ticket->customer_name,
            'customer_email' => $ticket->customer_email,
            'tags' => $ticket->tags->pluck('name')->all(),
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
