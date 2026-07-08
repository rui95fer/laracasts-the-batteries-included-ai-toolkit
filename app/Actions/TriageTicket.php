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
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class TriageTicket
{
    /**
     * Seconds to wait for a synchronous prompt before falling back to the queue.
     */
    private const SYNC_TIMEOUT_SECONDS = 15;

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
     *
     * If the synchronous prompt fails (timeout, rate limit, provider
     * outage), the run is marked as `queued` and dispatched to the
     * background queue so the user request still succeeds.
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

        $run = AiRun::create([
            'user_id' => $ticket->user_id,
            'ticket_id' => $ticket->id,
            'feature' => 'ticket-triage',
            'status' => 'running',
            'provider' => Lab::OpenRouter->value,
            'model' => 'openrouter/owl-alpha',
            'input_hash' => $inputHash,
            'started_at' => now(),
        ]);

        try {
            $response = $this->agent->prompt($prompt, timeout: self::SYNC_TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            return $this->queueAgent($run, $prompt, $e->getMessage());
        }

        $this->applyResponse($run, $ticket, $response);

        return $run;
    }

    private function applyResponse(AiRun $run, Ticket $ticket, AgentResponse $response): void
    {
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

        $this->markRunSucceeded($run, $response);
    }

    private function queueAgent(AiRun $run, string $prompt, string $failureMessage): AiRun
    {
        $run->update([
            'status' => 'queued',
            'finished_at' => now(),
            'error' => $failureMessage,
        ]);

        $runId = $run->id;

        $this->agent->queue($prompt)
            ->then(function (AgentResponse $response) use ($runId): void {
                $run = AiRun::query()->findOrFail($runId);
                $ticket = Ticket::query()->findOrFail($run->ticket_id);

                $this->applyResponse($run, $ticket, $response);
            })
            ->catch(function (Throwable $e) use ($runId): void {
                AiRun::query()->where('id', $runId)->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error' => $e->getMessage(),
                ]);
            });

        return $run->refresh();
    }

    private function markRunSucceeded(AiRun $run, AgentResponse $response): void
    {
        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
        ]);

        if ($response->usage) {
            AiUsage::create([
                'ai_run_id' => $run->id,
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->promptTokens + $response->usage->completionTokens,
                'cache_write_input_tokens' => $response->usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $response->usage->cacheReadInputTokens,
                'reasoning_tokens' => $response->usage->reasoningTokens,
            ]);
        }
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
}
