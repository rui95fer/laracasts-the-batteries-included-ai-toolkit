<?php

use App\Ai\Agents\TicketTriage;
use App\Listeners\RecordAiUsage;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

$providerStub = new class
{
    public function name(): string
    {
        return 'openai';
    }

    public function __call(string $method, array $args): mixed
    {
        return null;
    }
};

function buildPrompt(string $invocationId): AgentPrompt
{
    $stub = new class
    {
        public function name(): string
        {
            return 'openai';
        }
    };

    return new AgentPrompt(
        agent: new TicketTriage,
        prompt: 'Hello',
        attachments: collect(),
        provider: new class($stub) implements TextProvider
        {
            public function __construct(private object $stub) {}

            public function name(): string
            {
                return $this->stub->name();
            }

            public function __call(string $method, array $args): mixed
            {
                return null;
            }

            public function prompt(AgentPrompt $prompt): AgentResponse
            {
                return new AgentResponse('x', '', new Usage, new Meta('openai', 'gpt-4o-mini'));
            }

            public function stream(AgentPrompt $prompt): StreamableAgentResponse
            {
                throw new RuntimeException('not used');
            }

            public function textGateway(): TextGateway
            {
                throw new RuntimeException('not used');
            }

            public function useTextGateway(TextGateway $gateway): static
            {
                return $this;
            }

            public function defaultTextModel(): string
            {
                return 'gpt-4o-mini';
            }

            public function cheapestTextModel(): string
            {
                return 'gpt-4o-mini';
            }

            public function smartestTextModel(): string
            {
                return 'gpt-4o-mini';
            }
        },
        model: 'gpt-4o-mini',
        timeout: 30,
        invocationId: $invocationId,
    );
}

test('listener skips prompts that carry no usage payload', function () {
    $response = new AgentResponse('inv-no-usage', 'Hello', new Usage, new Meta('openai', 'gpt-4o-mini'));
    $reflection = new ReflectionClass($response);
    $property = $reflection->getProperty('usage');
    $property->setValue($response, null);

    $event = new AgentPrompted(
        'inv-no-usage',
        buildPrompt('inv-no-usage'),
        $response,
    );

    (new RecordAiUsage)->handle($event);

    expect(AiUsage::query()->where('invocation_id', 'inv-no-usage')->exists())->toBeFalse();
});

test('listener writes usage for a prompt and links to the run by invocation id', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $run = AiRun::create([
        'user_id' => $user->id,
        'ticket_id' => $ticket->id,
        'feature' => 'ticket-triage',
        'status' => 'running',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'invocation_id' => 'inv-link',
        'started_at' => now(),
    ]);

    $usage = new Usage(
        promptTokens: 5,
        completionTokens: 7,
        cacheWriteInputTokens: 1,
        cacheReadInputTokens: 2,
        reasoningTokens: 3,
    );

    $event = new AgentPrompted(
        'inv-link',
        buildPrompt('inv-link'),
        new AgentResponse('inv-link', 'Hello', $usage, new Meta('openai', 'gpt-4o-mini')),
    );

    (new RecordAiUsage)->handle($event);

    $row = AiUsage::query()->where('invocation_id', 'inv-link')->firstOrFail();

    expect($row->ai_run_id)->toBe($run->id)
        ->and($row->prompt_tokens)->toBe(5)
        ->and($row->completion_tokens)->toBe(7)
        ->and($row->cache_write_input_tokens)->toBe(1)
        ->and($row->cache_read_input_tokens)->toBe(2)
        ->and($row->reasoning_tokens)->toBe(3)
        ->and($row->total_tokens)->toBe(18);
});

test('listener is idempotent for the same invocation id', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $run = AiRun::create([
        'user_id' => $user->id,
        'ticket_id' => $ticket->id,
        'feature' => 'ticket-triage',
        'status' => 'running',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'invocation_id' => 'inv-dup',
        'started_at' => now(),
    ]);

    $build = fn () => new AgentPrompted(
        'inv-dup',
        buildPrompt('inv-dup'),
        new AgentResponse(
            'inv-dup',
            'Hello',
            new Usage(promptTokens: 2, completionTokens: 3),
            new Meta('openai', 'gpt-4o-mini'),
        ),
    );

    (new RecordAiUsage)->handle($build());
    (new RecordAiUsage)->handle($build());

    expect(AiUsage::query()->where('invocation_id', 'inv-dup')->count())->toBe(1)
        ->and(AiUsage::query()->where('ai_run_id', $run->id)->count())->toBe(1);
});

test('listener handles streamed events through the parent type hint', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    AiRun::create([
        'user_id' => $user->id,
        'ticket_id' => $ticket->id,
        'feature' => 'ticket-draft-reply',
        'status' => 'running',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'invocation_id' => 'inv-stream',
        'started_at' => now(),
    ]);

    $event = new AgentStreamed(
        'inv-stream',
        buildPrompt('inv-stream'),
        new StreamedAgentResponse(
            'inv-stream',
            collect([
                new TextDelta('evt-1', 'msg-1', 'Hello', time()),
                new StreamEnd('evt-2', 'stop', new Usage(promptTokens: 1, completionTokens: 2), time()),
            ]),
            new Meta('openai', 'gpt-4o-mini'),
        ),
    );

    (new RecordAiUsage)->handle($event);

    expect(AiUsage::query()->where('invocation_id', 'inv-stream')->exists())->toBeTrue();
});
