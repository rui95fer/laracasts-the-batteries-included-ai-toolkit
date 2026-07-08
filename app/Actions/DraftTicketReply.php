<?php

namespace App\Actions;

use App\Ai\Agents\TicketAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Throwable;

class DraftTicketReply
{
    /**
     * Seconds to wait before a slow streaming response is abandoned.
     */
    private const SYNC_TIMEOUT_SECONDS = 15;

    /**
     * Stream a draft reply for the latest customer message of the given ticket.
     *
     * The action creates an AiRun, returns the agent's streamable response
     * (which Laravel renders as text/event-stream), and persists the final
     * streamed text plus token usage via ->then(...) once streaming completes.
     */
    public function execute(Ticket $ticket, User $user): StreamableAgentResponse
    {
        $ticket = Ticket::query()->findOrFail($ticket->id);

        $prompt = 'Draft a concise, friendly reply to the most recent customer message.';

        $run = AiRun::create([
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
            'feature' => 'ticket-draft-reply',
            'status' => 'running',
            'provider' => Lab::OpenRouter->value,
            'model' => 'openrouter/owl-alpha',
            'started_at' => now(),
        ]);

        $agent = new TicketAssistant($ticket->id, $user->id);

        $response = $agent->stream($prompt, timeout: self::SYNC_TIMEOUT_SECONDS);

        $response->then(function ($streamed) use ($run): void {
            try {
                $run->update([
                    'status' => 'succeeded',
                    'finished_at' => now(),
                    'output_text' => $streamed->text,
                    'provider' => $streamed->meta->provider,
                    'model' => $streamed->meta->model,
                ]);

                if ($streamed->usage) {
                    AiUsage::create([
                        'ai_run_id' => $run->id,
                        'prompt_tokens' => $streamed->usage->promptTokens,
                        'completion_tokens' => $streamed->usage->completionTokens,
                        'total_tokens' => $streamed->usage->promptTokens + $streamed->usage->completionTokens,
                        'cache_write_input_tokens' => $streamed->usage->cacheWriteInputTokens,
                        'cache_read_input_tokens' => $streamed->usage->cacheReadInputTokens,
                        'reasoning_tokens' => $streamed->usage->reasoningTokens,
                    ]);
                }
            } catch (Throwable) {
                // Avoid masking the stream's outcome with persistence errors.
            }
        });

        return $response;
    }
}
