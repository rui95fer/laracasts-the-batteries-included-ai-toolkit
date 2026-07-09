<?php

namespace App\Actions;

use App\Ai\Agents\TicketAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class ChatWithTicketAssistant
{
    /**
     * Seconds to wait for a synchronous prompt before falling back to the queue.
     */
    private const SYNC_TIMEOUT_SECONDS = 15;

    public function execute(Ticket $ticket, User $user, string $message): ?AiRun
    {
        try {
            return Cache::lock("ticket-chat-{$ticket->id}", 120)
                ->block(120, fn (): AiRun => $this->runInsideLock($ticket, $user, $message));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function runInsideLock(Ticket $ticket, User $user, string $message): AiRun
    {
        $ticket = Ticket::query()->findOrFail($ticket->id);

        $agent = new TicketAssistant($ticket->id, $user->id);

        if ($ticket->ai_conversation_id) {
            $agent->continue($ticket->ai_conversation_id, as: $user);
        } else {
            $agent->forUser($user);
        }

        $inputHash = md5($message);

        $run = AiRun::create([
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
            'feature' => 'ticket-chat',
            'status' => 'running',
            'provider' => Lab::OpenRouter->value,
            'model' => 'openrouter/owl-alpha',
            'input_hash' => $inputHash,
            'started_at' => now(),
        ]);

        try {
            $response = $agent->prompt($message, timeout: self::SYNC_TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            return $this->queueAgent($run, $ticket, $user, $message, $e->getMessage());
        }

        $this->applyResponse($run, $ticket, $user, $message, $response);

        return $run;
    }

    private function applyResponse(AiRun $run, Ticket $ticket, User $user, string $message, AgentResponse $response): void
    {
        $conversationId = $response->conversationId;

        DB::transaction(function () use ($ticket, $user, $message, $response, $conversationId): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            $ticket->messages()->create([
                'type' => TicketMessageType::SystemMessage,
                'body' => "AI chat prompt: {$message}",
                'author_name' => $user->name,
                'author_email' => $user->email,
            ]);

            $ticket->messages()->create([
                'type' => TicketMessageType::SystemMessage,
                'body' => "AI chat reply: {$response->text}",
                'author_name' => 'AI Assistant',
                'author_email' => config('mail.from.address', 'system@localhost'),
            ]);

            if ($conversationId !== null) {
                $ticket->ai_conversation_id = $conversationId;
                $ticket->save();
            }
        });

        $this->markRunSucceeded($run, $response);
    }

    private function queueAgent(AiRun $run, Ticket $ticket, User $user, string $message, string $failureMessage): AiRun
    {
        DB::transaction(function () use ($ticket, $user, $message): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            $ticket->messages()->create([
                'type' => TicketMessageType::SystemMessage,
                'body' => "AI chat prompt: {$message}",
                'author_name' => $user->name,
                'author_email' => $user->email,
            ]);
        });

        $run->update([
            'status' => 'queued',
            'finished_at' => now(),
            'error' => $failureMessage,
        ]);

        $runId = $run->id;
        $ticketId = $ticket->id;

        $chatAgent = new TicketAssistant($ticket->id, $user->id);

        if ($ticket->ai_conversation_id) {
            $chatAgent->continue($ticket->ai_conversation_id, as: $user);
        } else {
            $chatAgent->forUser($user);
        }

        $chatAgent->queue($message)
            ->then(function (AgentResponse $response) use ($runId, $ticketId): void {
                $run = AiRun::query()->findOrFail($runId);
                $ticket = Ticket::query()->findOrFail($ticketId);

                DB::transaction(function () use ($ticket, $response): void {
                    $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

                    $ticket->messages()->create([
                        'type' => TicketMessageType::SystemMessage,
                        'body' => "AI chat reply: {$response->text}",
                        'author_name' => 'AI Assistant',
                        'author_email' => config('mail.from.address', 'system@localhost'),
                    ]);

                    if ($response->conversationId !== null) {
                        $ticket->ai_conversation_id = $response->conversationId;
                        $ticket->save();
                    }
                });

                $this->markRunSucceeded($run, $response);
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
            'invocation_id' => $response->invocationId,
        ]);

        AiUsage::query()
            ->where('invocation_id', $response->invocationId)
            ->whereNull('ai_run_id')
            ->update(['ai_run_id' => $run->id]);
    }
}
