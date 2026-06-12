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
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;
use Throwable;

class ChatWithTicketAssistant
{
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

        $agent = new TicketAssistant($ticket->id);

        if ($ticket->ai_conversation_id) {
            $agent->continue($ticket->ai_conversation_id, as: $user);
        } else {
            $agent->forUser($user);
        }

        $inputHash = md5($message);

        $attributes = $this->resolveAgentAttributes($agent);

        $run = AiRun::create([
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
            'feature' => 'ticket-chat',
            'status' => 'running',
            'provider' => $attributes['provider'],
            'model' => $attributes['model'],
            'input_hash' => $inputHash,
            'started_at' => now(),
        ]);

        try {
            $response = $agent->prompt($message);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

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

    /**
     * @return array{provider: string, model: string|null}
     */
    private function resolveAgentAttributes(TicketAssistant $agent): array
    {
        $reflection = new ReflectionClass($agent);
        $provider = ($reflection->getAttributes(ProviderAttribute::class)[0] ?? null)?->newInstance()->value;
        $model = ($reflection->getAttributes(ModelAttribute::class)[0] ?? null)?->newInstance()->value;

        return [
            'provider' => $provider === null ? '' : ($provider instanceof Lab ? $provider->value : (string) $provider),
            'model' => $model === null ? null : (string) $model,
        ];
    }
}
