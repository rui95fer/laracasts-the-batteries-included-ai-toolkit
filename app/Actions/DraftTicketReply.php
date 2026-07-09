<?php

namespace App\Actions;

use App\Ai\Agents\TicketAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StreamableAgentResponse;

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
     * streamed text plus the invocation id once streaming completes so the
     * AI event listener can correlate token usage to this run.
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
                    'invocation_id' => $streamed->invocationId,
                ]);

                AiUsage::query()
                    ->where('invocation_id', $streamed->invocationId)
                    ->whereNull('ai_run_id')
                    ->update(['ai_run_id' => $run->id]);
            } catch (\Throwable) {
                // Avoid masking the stream's outcome with persistence errors.
            }
        });

        return $response;
    }
}
