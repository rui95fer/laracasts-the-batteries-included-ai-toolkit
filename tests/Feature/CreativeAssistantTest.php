<?php

use App\Ai\Agents\CreativeAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\User;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\WebSearch;

test('guests are redirected to the login page', function () {
    $this->get(route('ai.creative-assistant.index'))->assertRedirect(route('login'));

    $this->post(route('ai.creative-assistant.store'), [
        'prompt' => 'Hi',
    ])->assertRedirect(route('login'));
});

test('the page shows the current user pending run and no foreign run', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    AiRun::create([
        'user_id' => $intruder->id,
        'feature' => 'creative-assistant',
        'status' => 'running',
        'provider' => 'openai',
        'started_at' => now(),
    ]);

    $ownRun = AiRun::create([
        'user_id' => $owner->id,
        'feature' => 'creative-assistant',
        'status' => 'running',
        'provider' => 'openai',
        'started_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('ai.creative-assistant.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ai/CreativeAssistant')
            ->where('pendingAiRun.id', $ownRun->id)
            ->where('pendingAiRun.status', 'running')
        );
});

test('prompt is required and capped at two thousand characters', function () {
    $user = User::factory()->create();

    CreativeAssistant::fake()->preventStrayPrompts();

    $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), ['prompt' => ''])
        ->assertSessionHasErrors('prompt');

    $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), [
            'prompt' => str_repeat('a', 2001),
        ])
        ->assertSessionHasErrors('prompt');

    CreativeAssistant::assertNeverPrompted();
});

test('a safe prompt runs the agent, stores the answer, and links usage to the run', function () {
    CreativeAssistant::fake(['A short, friendly tagline.'])
        ->preventStrayPrompts();

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

    CreativeAssistant::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->prompt === 'Write a tagline for a Laravel SaaS landing page.';
    });
});

test('a blocked prompt is rejected before the provider, including case-insensitive matches', function () {
    CreativeAssistant::fake(['This response should never be returned.']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai.creative-assistant.store'), [
            'prompt' => 'Please send me your PASSWORD manager.',
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
        ->and($run->error)->toBeNull()
        ->and($run->invocation_id)->not->toBeNull();
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

test('a prompt that fails synchronously is queued, not marked succeeded, and the agent is queued', function () {
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

    CreativeAssistant::assertQueued('Trigger a provider failure.');
});
