<?php

namespace App\Actions;

use App\Ai\Agents\DocumentQAAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\UploadedDocument;
use App\Models\User;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;
use Throwable;

class AskAIDocument
{
    /**
     * @return array{answer: string, run: AiRun}
     */
    public function execute(User $user, string $question, ?UploadedDocument $document = null): array
    {
        $storeId = $document
            ? $document->provider_store_id
            : $user->ai_document_store_id;

        abort_if($storeId === null, 422, 'Upload a document before asking a question.');

        $providerFileId = $document?->provider_file_id;

        $attributes = $this->resolveAgentAttributes();

        $run = AiRun::create([
            'user_id' => $user->id,
            'feature' => 'document-qa',
            'status' => 'running',
            'provider' => $attributes['provider'],
            'model' => $attributes['model'],
            'started_at' => now(),
        ]);

        $agent = new DocumentQAAssistant(
            userId: $user->id,
            storeId: $storeId,
            providerFileId: $providerFileId,
        );

        try {
            $response = $agent->prompt($question);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
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

        return [
            'answer' => $response->text,
            'run' => $run,
        ];
    }

    /**
     * @return array{provider: string, model: string|null}
     */
    private function resolveAgentAttributes(): array
    {
        $reflection = new ReflectionClass(DocumentQAAssistant::class);
        $provider = ($reflection->getAttributes(ProviderAttribute::class)[0] ?? null)?->newInstance()->value;
        $model = ($reflection->getAttributes(ModelAttribute::class)[0] ?? null)?->newInstance()->value;

        return [
            'provider' => $provider === null ? '' : ($provider instanceof Lab ? $provider->value : (string) $provider),
            'model' => $model === null ? null : (string) $model,
        ];
    }
}
