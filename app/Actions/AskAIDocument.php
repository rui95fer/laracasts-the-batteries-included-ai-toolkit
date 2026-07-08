<?php

namespace App\Actions;

use App\Ai\Agents\DocumentQAAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\UploadedDocument;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class AskAIDocument
{
    /**
     * Seconds to wait for a synchronous prompt before falling back to the queue.
     */
    private const SYNC_TIMEOUT_SECONDS = 15;

    /**
     * @return array{answer: string, run: AiRun, status: string}
     */
    public function execute(User $user, string $question, ?UploadedDocument $document = null): array
    {
        $storeId = $document
            ? $document->provider_store_id
            : $user->ai_document_store_id;

        abort_if($storeId === null, 422, 'Upload a document before asking a question.');

        $providerFileId = $document?->provider_file_id;

        $run = AiRun::create([
            'user_id' => $user->id,
            'feature' => 'document-qa',
            'status' => 'running',
            'provider' => Lab::OpenAI->value,
            'model' => 'gpt-4o-mini',
            'started_at' => now(),
        ]);

        $agent = new DocumentQAAssistant(
            userId: $user->id,
            storeId: $storeId,
            providerFileId: $providerFileId,
        );

        try {
            $response = $agent->prompt($question, timeout: self::SYNC_TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->queueAgent($run, $question, $e->getMessage(), $storeId, $providerFileId, $user->id);

            return [
                'answer' => '',
                'run' => $run->refresh(),
                'status' => 'queued',
            ];
        }

        $this->markRunSucceeded($run, $response);
        $this->logUsage($run, $response);

        return [
            'answer' => $response->text,
            'run' => $run->refresh(),
            'status' => 'succeeded',
        ];
    }

    private function queueAgent(
        AiRun $run,
        string $question,
        string $failureMessage,
        string $storeId,
        ?string $providerFileId,
        int $userId,
    ): void {
        $run->update([
            'status' => 'queued',
            'finished_at' => now(),
            'error' => $failureMessage,
        ]);

        $runId = $run->id;

        $agent = new DocumentQAAssistant(
            userId: $userId,
            storeId: $storeId,
            providerFileId: $providerFileId,
        );

        $agent->queue($question)
            ->then(function (AgentResponse $response) use ($runId): void {
                $run = AiRun::query()->findOrFail($runId);

                $this->markRunSucceeded($run, $response);
                $this->logUsage($run, $response);
                $run->update(['output_text' => $response->text]);
            })
            ->catch(function (Throwable $e) use ($runId): void {
                AiRun::query()->where('id', $runId)->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error' => $e->getMessage(),
                ]);
            });
    }

    private function markRunSucceeded(AiRun $run, AgentResponse $response): void
    {
        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
            'output_text' => $response->text,
        ]);
    }

    private function logUsage(AiRun $run, AgentResponse $response): void
    {
        if (! $response->usage) {
            return;
        }

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
