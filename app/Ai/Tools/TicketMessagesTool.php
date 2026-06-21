<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class TicketMessagesTool implements Tool
{
    private const MIN_COUNT = 1;

    private const MAX_COUNT = 5;

    public function __construct(
        public readonly int $ticketId,
        public readonly ?User $user = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Fetches the most recent ticket messages in chronological order, with role, body, '
            .'author, and created_at for each message.';
    }

    public function name(): string
    {
        return 'ticket_messages';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->user ?? auth()->user();

        if (! $user) {
            return 'unauthorized';
        }

        $ticket = Ticket::query()->find($this->ticketId);

        if (! $ticket) {
            return 'unauthorized';
        }

        if (! Gate::forUser($user)->allows('view', $ticket)) {
            return 'unauthorized';
        }

        $count = max(self::MIN_COUNT, min(self::MAX_COUNT, (int) $request->integer('count', 3)));

        $messages = $ticket->messages()
            ->latest()
            ->limit($count)
            ->get()
            ->reverse()
            ->map(fn ($message): array => [
                'type' => $message->type->value,
                'body' => $message->body,
                'author_name' => $message->author_name,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->values();

        return $messages->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()
                ->min(self::MIN_COUNT)
                ->max(self::MAX_COUNT)
                ->required(),
        ];
    }
}
