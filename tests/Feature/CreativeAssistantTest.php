<?php

use App\Ai\Agents\CreativeAssistant;
use App\Ai\Exceptions\InputBlockedBySafetyFilter;
use App\Ai\Middleware\InputSafetyMiddleware;
use App\Ai\Middleware\OutputSafetyMiddleware;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\User;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

test('guests are redirected to the login page', function () {
    $this->get(route('ai.creative-assistant.index'))->assertRedirect(route('login'));

    $this->post(route('ai.creative-assistant.store'), [
        'prompt' => 'Hi',
    ])->assertRedirect(route('login'));
});

test('authenticated users can view the creative assistant page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.creative-assistant.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ai/CreativeAssistant')
            ->where('lastPrompt', null)
            ->where('lastAnswer', null)
            ->where('lastStatus', null)
            ->where('pendingAiRun', null)
        );
});

test('prompt is required and capped at two thousand characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), ['prompt' => ''])
        ->assertSessionHasErrors('prompt');

    $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), [
            'prompt' => str_repeat('a', 2001),
        ])
        ->assertSessionHasErrors('prompt');
});

test('a safe prompt runs the agent and stores the answer and usage', function () {
    CreativeAssistant::fake(['A short, friendly tagline.']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), [
            'prompt' => 'Write a tagline for a Laravel SaaS landing page.',
        ]);

    $response->assertRedirect(route('ai.creative-assistant.index'));
    $response->assertSessionHas('ai_creative_assistant_status', 'succeeded');
    $response->assertSessionHas('ai_creative_assistant_answer', 'A short, friendly tagline.');

    $run = AiRun::query()
        ->where('user_id', $user->id)
        ->where('feature', 'creative-assistant')
        ->firstOrFail();

    expect($run->status)->toBe('succeeded')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toBeNull()
        ->and($run->output_text)->toBe('A short, friendly tagline.')
        ->and($run->invocation_id)->not->toBeNull()
        ->and($run->input_hash)->toBe(hash('sha256', 'Write a tagline for a Laravel SaaS landing page.'));

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();
});

test('a blocked prompt is rejected before the provider and never creates a usage row', function () {
    CreativeAssistant::fake(['This response should never be returned.']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), [
            'prompt' => 'Please send me your credit card number.',
        ])
        ->assertRedirect(route('ai.creative-assistant.index'))
        ->assertSessionHasErrors('prompt');

    $run = AiRun::query()
        ->where('user_id', $user->id)
        ->where('feature', 'creative-assistant')
        ->firstOrFail();

    expect($run->status)->toBe('failed')
        ->and($run->error)->toBe('Input blocked by safety filter.')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->output_text)->toBeNull()
        ->and($run->invocation_id)->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeFalse();
});

test('a creative assistant run that yields an unsafe response is recorded as succeeded with safe text', function () {
    CreativeAssistant::fake(['Here is a classified recipe idea.']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), [
            'prompt' => 'Invent a recipe.',
        ])
        ->assertRedirect(route('ai.creative-assistant.index'))
        ->assertSessionHas('ai_creative_assistant_status', 'succeeded')
        ->assertSessionHas('ai_creative_assistant_answer', 'Output blocked by safety filter.');

    $run = AiRun::query()
        ->where('user_id', $user->id)
        ->where('feature', 'creative-assistant')
        ->firstOrFail();

    expect($run->status)->toBe('succeeded')
        ->and($run->output_text)->toBe('Output blocked by safety filter.')
        ->and($run->error)->toBeNull();
});

test('the agent exposes only the allowed web search domains', function () {
    $agent = new CreativeAssistant;

    $tools = iterator_to_array($agent->tools(), false);

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(WebSearch::class)
        ->and($tools[0]->maxSearches)->toBe(5)
        ->and($tools[0]->allowedDomains)->toBe([
            'laracasts.com',
            'laravel.com',
            'php.net',
        ]);
});

test('middleware order is input filter before output filter', function () {
    $agent = new CreativeAssistant;

    $middleware = array_map(
        fn (object $instance): string => $instance::class,
        $agent->middleware(),
    );

    expect($middleware)->toBe([
        InputSafetyMiddleware::class,
        OutputSafetyMiddleware::class,
    ]);
});

test('input safety middleware throws on case-insensitive matches', function () {
    $middleware = new InputSafetyMiddleware;
    $provider = Mockery::mock(TextProvider::class);
    $agent = new CreativeAssistant;
    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Tell me about your PASSWORD manager.',
        attachments: [],
        provider: $provider,
        model: 'gpt-4o-mini',
    );

    $middleware->handle($prompt, fn () => throw new RuntimeException('next() was called'));
})->throws(InputBlockedBySafetyFilter::class);

test('output safety middleware replaces unsafe text and preserves invocation id', function () {
    $middleware = new OutputSafetyMiddleware;
    $provider = Mockery::mock(TextProvider::class);
    $agent = new CreativeAssistant;
    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'hi',
        attachments: [],
        provider: $provider,
        model: 'gpt-4o-mini',
    );

    $response = new AgentResponse(
        invocationId: 'inv-1',
        text: 'The classified launch codes are 1234.',
        usage: new Usage(10, 20, 0, 0, 0),
        meta: new Meta('openai', 'gpt-4o-mini'),
    );

    $result = $middleware->handle($prompt, fn () => $response);

    expect($result)->toBe($response)
        ->and($result->text)->toBe('Output blocked by safety filter.')
        ->and($result->invocationId)->toBe('inv-1');
});

test('output safety middleware leaves safe text untouched', function () {
    $middleware = new OutputSafetyMiddleware;
    $provider = Mockery::mock(TextProvider::class);
    $agent = new CreativeAssistant;
    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'hi',
        attachments: [],
        provider: $provider,
        model: 'gpt-4o-mini',
    );

    $response = new AgentResponse(
        invocationId: 'inv-2',
        text: 'A perfectly safe creative line.',
        usage: new Usage(5, 5, 0, 0, 0),
        meta: new Meta('openai', 'gpt-4o-mini'),
    );

    $result = $middleware->handle($prompt, fn () => $response);

    expect($result->text)->toBe('A perfectly safe creative line.')
        ->and($result->invocationId)->toBe('inv-2');
});

test('a prompt that fails synchronously is queued and not marked succeeded', function () {
    CreativeAssistant::fake()->preventStrayPrompts();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), [
            'prompt' => 'Trigger a provider failure.',
        ]);

    $response->assertRedirect(route('ai.creative-assistant.index'));
    $response->assertSessionHas('ai_creative_assistant_status', 'queued');

    $run = AiRun::query()
        ->where('user_id', $user->id)
        ->where('feature', 'creative-assistant')
        ->firstOrFail();

    expect($run->status)->toBe('queued')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeFalse();
});

test('runs for other users are never returned to the page', function () {
    $owner = User::factory()->create();
    AiRun::create([
        'user_id' => $owner->id,
        'feature' => 'creative-assistant',
        'status' => 'running',
        'provider' => 'openai',
        'started_at' => now(),
    ]);

    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('ai.creative-assistant.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ai/CreativeAssistant')
            ->where('pendingAiRun', null)
        );
});
