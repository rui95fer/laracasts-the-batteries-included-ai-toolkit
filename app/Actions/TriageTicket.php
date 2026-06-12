<?php

namespace App\Actions;

use App\Ai\Agents\TicketTriage;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\TicketDepartment;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketSentiment;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;
use Throwable;

class TriageTicket
{
    public function __construct(
        private readonly TicketTriage $agent = new TicketTriage,
        private readonly SyncTicketTags $syncTags = new SyncTicketTags,
    ) {}

    /**
     * Triage the latest message of a ticket using the configured agent.
     *
     * The action is safe to invoke repeatedly: a per-ticket cache lock
     * prevents concurrent runs, the input hash is compared against the
     * latest successful run for the ticket to skip redundant work, and
     * the latest message is re-read inside the lock so that older
     * in-flight runs cannot overwrite newer ticket state.
     */
    public function execute(Ticket $ticket): ?AiRun
    {
        $initialTriageSourceId = $this->latestTriageSourceId($ticket);

        try {
            return Cache::lock("ticket-triage-{$ticket->id}", 120)
                ->block(120, fn (): ?AiRun => $this->runInsideLock($ticket, $initialTriageSourceId));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function runInsideLock(Ticket $ticket, int $initialTriageSourceId): ?AiRun
    {
        $ticket = Ticket::query()->findOrFail($ticket->id);

        $triageSourceId = $this->latestTriageSourceId($ticket);

        if ($triageSourceId !== $initialTriageSourceId) {
            return null;
        }

        $latestMessage = TicketMessage::query()->findOrFail($triageSourceId);

        $prompt = "Subject: {$ticket->subject}\nMessage: {$latestMessage->body}";
        $inputHash = md5($prompt);

        if ($this->hasMatchingSucceededRun($ticket, $inputHash)) {
            return null;
        }

        $attributes = $this->resolveAgentAttributes();

        $run = AiRun::create([
            'user_id' => $ticket->user_id,
            'ticket_id' => $ticket->id,
            'feature' => 'ticket-triage',
            'status' => 'running',
            'provider' => $attributes['provider'],
            'model' => $attributes['model'],
            'input_hash' => $inputHash,
            'started_at' => now(),
        ]);

        try {
            $response = $this->agent->prompt($prompt);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        DB::transaction(function () use ($ticket, $response): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            $ticket->update([
                'priority' => TicketPriority::from($response['priority']),
                'department' => TicketDepartment::from($response['department']),
                'sentiment' => TicketSentiment::from($response['sentiment']),
            ]);

            $this->syncTags->execute($ticket, $response['tags']);

            if (filled($response['summary'])) {
                $ticket->messages()->create([
                    'type' => TicketMessageType::SystemMessage,
                    'body' => "AI summary: {$response['summary']}",
                    'author_name' => 'System',
                    'author_email' => config('mail.from.address', 'system@localhost'),
                ]);
            }
        });

        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
        ]);

        AiUsage::create([
            'ai_run_id' => $run->id,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
            'total_tokens' => $response->usage->promptTokens + $response->usage->completionTokens,
            'cache_write_input_tokens' => $response->usage->cacheWriteInputTokens,
            'cache_read_input_tokens' => $response->usage->cacheReadInputTokens,
            'reasoning_tokens' => $response->usage->reasoningTokens,
        ]);

        return $run;
    }

    private function latestMessageId(Ticket $ticket): int
    {
        $id = $ticket->messages()->latest()->value('id');

        if ($id === null) {
            throw (new ModelNotFoundException)->setModel(TicketMessage::class);
        }

        return (int) $id;
    }

    private function latestTriageSourceId(Ticket $ticket): int
    {
        $id = $ticket->messages()
            ->where('type', '!=', TicketMessageType::SystemMessage->value)
            ->latest()
            ->value('id');

        if ($id === null) {
            return $this->latestMessageId($ticket);
        }

        return (int) $id;
    }

    private function hasMatchingSucceededRun(Ticket $ticket, string $inputHash): bool
    {
        return AiRun::query()
            ->where('ticket_id', $ticket->id)
            ->where('feature', 'ticket-triage')
            ->where('status', 'succeeded')
            ->where('input_hash', $inputHash)
            ->exists();
    }

    /**
     * @return array{provider: string, model: string|null}
     */
    private function resolveAgentAttributes(): array
    {
        $reflection = new ReflectionClass($this->agent);
        $provider = ($reflection->getAttributes(ProviderAttribute::class)[0] ?? null)?->newInstance()->value;
        $model = ($reflection->getAttributes(ModelAttribute::class)[0] ?? null)?->newInstance()->value;

        return [
            'provider' => $provider instanceof Lab ? $provider->value : (string) $provider,
            'model' => $model,
        ];
    }
}
