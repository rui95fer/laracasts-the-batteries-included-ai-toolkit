<?php

namespace App\Listeners;

use App\Models\AiRun;
use App\Models\AiUsage;
use Laravel\Ai\Events\AgentPrompted;

class RecordAiUsage
{
    /**
     * Persist token usage for one AI invocation.
     *
     * The Laravel AI SDK dispatches `AgentPrompted` after a synchronous
     * prompt and `AgentStreamed` after a streamed response. The shared
     * `invocationId` is the correlation key between the SDK call and
     * the `AiRun` the action created beforehand, so the usage row is
     * found through it instead of relying on request context.
     */
    public function handle(AgentPrompted $event): void
    {
        $usage = $event->response->usage ?? null;

        if ($usage === null) {
            return;
        }

        if (AiUsage::query()->where('invocation_id', $event->invocationId)->exists()) {
            return;
        }

        AiUsage::create([
            'ai_run_id' => AiRun::query()
                ->where('invocation_id', $event->invocationId)
                ->value('id'),
            'invocation_id' => $event->invocationId,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'total_tokens' => $usage->promptTokens
                + $usage->completionTokens
                + $usage->cacheWriteInputTokens
                + $usage->cacheReadInputTokens
                + $usage->reasoningTokens,
        ]);
    }
}
