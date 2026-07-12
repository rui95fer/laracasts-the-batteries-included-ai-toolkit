<?php

namespace App\Actions;

use App\Ai\Agents\CreativeAssistant;
use App\Ai\Exceptions\InputBlockedBySafetyFilter;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class PromptCreativeAssistant
{
    /**
     * Seconds to wait for a synchronous prompt before falling back to the queue.
     */
    private const SYNC_TIMEOUT_SECONDS = 15;

    /**
     * The "output blocked by safety filter" message that replaces unsafe text.
     */
    public const SAFE_OUTPUT_MESSAGE = 'Output blocked by safety filter.';

    private const FEATURE = 'creative-assistant';

    /**
     * @return array{answer: string, run: AiRun, status: string}
     */
    public function execute(User $user, string $prompt): array
    {
        $run = AiRun::create([
            'user_id' => $user->id,
            'feature' => self::FEATURE,
            'status' => 'running',
            'provider' => Lab::OpenAI->value,
            'started_at' => now(),
            'input_hash' => hash('sha256', $prompt),
        ]);

        try {
            $response = (new CreativeAssistant)->prompt($prompt, timeout: self::SYNC_TIMEOUT_SECONDS);
        } catch (InputBlockedBySafetyFilter $blocked) {
            return $this->markBlocked($run);
        } catch (Throwable $e) {
            return $this->queueAgent($run, $user, $prompt, $e->getMessage());
        }

        $this->markRunSucceeded($run, $response);

        return [
            'answer' => $response->text,
            'run' => $run->refresh(),
            'status' => 'succeeded',
        ];
    }

    /**
     * @return array{answer: string, run: AiRun, status: string}
     */
    private function markBlocked(AiRun $run): array
    {
        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error' => 'Input blocked by safety filter.',
            'output_text' => null,
        ]);

        return [
            'answer' => '',
            'run' => $run->refresh(),
            'status' => 'blocked',
        ];
    }

    /**
     * @return array{answer: string, run: AiRun, status: string}
     */
    private function queueAgent(AiRun $run, User $user, string $prompt, string $failureMessage): array
    {
        $run->update([
            'status' => 'queued',
            'finished_at' => now(),
            'error' => $failureMessage,
        ]);

        $runId = $run->id;
        $inputHash = $run->input_hash;

        (new CreativeAssistant)
            ->queue($prompt)
            ->then(function (AgentResponse $response) use ($runId): void {
                $run = AiRun::query()->find($runId);

                if ($run === null) {
                    return;
                }

                $this->markRunSucceeded($run, $response);
            })
            ->catch(function (Throwable $e) use ($runId): void {
                AiRun::query()->where('id', $runId)->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error' => $e->getMessage(),
                ]);
            });

        return [
            'answer' => '',
            'run' => $run->refresh(),
            'status' => 'queued',
        ];
    }

    private function markRunSucceeded(AiRun $run, AgentResponse $response): void
    {
        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
            'output_text' => $response->text,
            'error' => null,
            'invocation_id' => $response->invocationId,
        ]);

        AiUsage::query()
            ->where('invocation_id', $response->invocationId)
            ->whereNull('ai_run_id')
            ->update(['ai_run_id' => $run->id]);
    }
}
