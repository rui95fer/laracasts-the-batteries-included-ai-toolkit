<?php

use App\Ai\Agents\TicketTriage;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use App\TicketDepartment;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketSentiment;
use Laravel\Ai\Prompts\AgentPrompt;

test('guests are redirected to the login page', function () {
    $ticket = Ticket::factory()->create();

    $this->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect(route('login'));
});

test('users cannot triage tickets owned by another user', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertForbidden();
});

test('triage applies the agent response to the ticket and records the run and usage', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'I was charged twice for my subscription.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake([
        [
            'priority' => 'high',
            'department' => 'billing',
            'sentiment' => 'negative',
            'tags' => ['billing', 'refund'],
            'summary' => 'Customer requests a refund for a duplicate charge.',
        ],
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();

    $ticket->refresh();

    expect($ticket)
        ->priority->toBe(TicketPriority::High)
        ->department->toBe(TicketDepartment::Billing)
        ->sentiment->toBe(TicketSentiment::Negative)
        ->and($ticket->tags()->pluck('slug')->all())->toBe(['billing', 'refund']);

    $summaryMessage = $ticket->messages()
        ->where('type', TicketMessageType::SystemMessage)
        ->first();

    expect($summaryMessage)
        ->not->toBeNull()
        ->and($summaryMessage->body)->toBe('AI summary: Customer requests a refund for a duplicate charge.');

    $run = AiRun::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($run)
        ->feature->toBe('ticket-triage')
        ->status->toBe('succeeded')
        ->user_id->toBe($user->id)
        ->provider->not->toBeNull()
        ->model->not->toBeNull()
        ->input_hash->not->toBeNull()
        ->invocation_id->not->toBeNull()
        ->finished_at->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();

    TicketTriage::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, 'I was charged twice for my subscription.')
            && str_contains($prompt->prompt, 'Subject:');
    });
});

test('triage is idempotent for the same input hash', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'Idempotent triage body.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake([
        [
            'priority' => 'normal',
            'department' => 'support',
            'sentiment' => 'neutral',
            'tags' => [],
            'summary' => '',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();

    $runsAfterFirst = AiRun::query()->where('ticket_id', $ticket->id)->count();
    $messagesAfterFirst = $ticket->messages()->count();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();

    expect(AiRun::query()->where('ticket_id', $ticket->id)->count())->toBe($runsAfterFirst);
    expect($ticket->messages()->count())->toBe($messagesAfterFirst);
});

test('triage falls back to the queue when the sync prompt fails', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'The provider is timing out.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake()->preventStrayPrompts();

    $this->actingAs($user)
        ->from(route('tickets.show', $ticket))
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect(route('tickets.show', $ticket));

    $run = AiRun::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($run->status)->toBe('queued')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeFalse();

    TicketTriage::assertQueued(fn ($queued) => str_contains($queued->prompt, 'The provider is timing out.'));
});
