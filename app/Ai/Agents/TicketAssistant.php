<?php

namespace App\Ai\Agents;

use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenRouter)]
#[Model('openrouter/owl-alpha')]
#[MaxTokens(1500)]
#[Timeout(120)]
class TicketAssistant implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function __construct(public readonly int $ticketId) {}

    public function instructions(): Stringable|string
    {
        return 'You are a support assistant. Stay strictly within the current ticket. '
            ."If you are unsure, ask a clarifying question.\n\n"
            .$this->ticketContext();
    }

    /**
     * Limit how much of the prior conversation the SDK reloads.
     */
    protected function maxConversationMessages(): int
    {
        return 20;
    }

    private function ticketContext(): string
    {
        $ticket = Ticket::query()
            ->with(['tags', 'messages' => fn ($query) => $query->latest()->limit(5)])
            ->find($this->ticketId);

        if (! $ticket) {
            return 'Ticket context unavailable.';
        }

        $tags = $this->tagNames($ticket->tags);
        $messages = $this->recentMessageText($ticket->messages);

        return "Subject: {$ticket->subject}\n"
            ."Status: {$ticket->status->value}\n"
            .'Priority: '.$this->nullableEnumValue($ticket->priority)."\n"
            .'Department: '.$this->nullableEnumValue($ticket->department)."\n"
            .'Sentiment: '.$this->nullableEnumValue($ticket->sentiment)."\n"
            ."Tags: {$tags}\n"
            ."Recent messages:\n{$messages}";
    }

    /**
     * @param  EloquentCollection<int, Tag>  $tags
     */
    private function tagNames(EloquentCollection $tags): string
    {
        $names = $tags->pluck('name')->implode(', ');

        return $names !== '' ? $names : 'none';
    }

    /**
     * @param  EloquentCollection<int, TicketMessage>  $messages
     */
    private function recentMessageText(EloquentCollection $messages): string
    {
        if ($messages->isEmpty()) {
            return 'none';
        }

        return $messages
            ->reverse()
            ->map(fn ($message): string => "{$message->type->value}: {$message->body}")
            ->implode("\n");
    }

    private function nullableEnumValue(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return is_object($value) && property_exists($value, 'value')
            ? (string) $value->value
            : (string) $value;
    }
}
