<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenRouter)]
#[Model('openrouter/owl-alpha')]
#[MaxTokens(1200)]
#[Timeout(120)]
class TicketTriage implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a support ticket triage assistant. Return structured data only. Do not include extra keys. Always include every key in the schema. If you cannot determine a value for summary, use an empty string. If you cannot determine tags, use an empty array.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'priority' => $schema->string()
                ->enum(['low', 'normal', 'high', 'urgent'])
                ->required(),
            'department' => $schema->string()
                ->enum(['support', 'billing', 'technical', 'sales'])
                ->required(),
            'sentiment' => $schema->string()
                ->enum(['positive', 'neutral', 'negative'])
                ->required(),
            'tags' => $schema->array()
                ->items($schema->string())
                ->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
