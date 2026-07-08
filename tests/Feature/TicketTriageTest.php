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

test('user can triage a ticket', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create([
        'priority' => TicketPriority::Normal,
        'department' => TicketDepartment::Support,
        'sentiment' => TicketSentiment::Neutral,
    ]);
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'I was charged twice for my subscription.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();

    $ticket->refresh();

    expect($ticket->priority)->toBeInstanceOf(TicketPriority::class);
    expect($ticket->department)->toBeInstanceOf(TicketDepartment::class);
    expect($ticket->sentiment)->toBeInstanceOf(TicketSentiment::class);

    $run = AiRun::first();
    expect($run)->not->toBeNull();
    expect($run->user_id)->toBe($user->id);
    expect($run->ticket_id)->toBe($ticket->id);
    expect($run->feature)->toBe('ticket-triage');
    expect($run->status)->toBe('succeeded');
    expect($run->provider)->not->toBeNull();
    expect($run->model)->not->toBeNull();
    expect($run->input_hash)->not->toBeNull();
    expect($run->finished_at)->not->toBeNull();

    $usage = AiUsage::first();
    expect($usage)->not->toBeNull();
    expect($usage->ai_run_id)->toBe($run->id);
});

test('triaging creates an AI summary message', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'Help with my account.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket));

    $summaryMessage = $ticket->messages()
        ->where('type', TicketMessageType::SystemMessage)
        ->first();

    expect($summaryMessage)->not->toBeNull();
    expect($summaryMessage->body)->toStartWith('AI summary:');
});

test('triaging syncs tags from the AI response', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'Help with my account.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake([
        [
            'priority' => 'normal',
            'department' => 'support',
            'sentiment' => 'neutral',
            'tags' => ['billing', 'refund'],
            'summary' => 'Customer needs billing assistance.',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket));

    expect($ticket->refresh()->tags()->pluck('slug')->all())->toBe(['billing', 'refund']);
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

    TicketTriage::fake();

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

test('triage re-runs when a new message is added to the ticket', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'Original message body.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();

    expect(AiRun::query()->where('ticket_id', $ticket->id)->count())->toBe(1);

    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'Follow-up message body.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();

    expect(AiRun::query()->where('ticket_id', $ticket->id)->count())->toBe(2);
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

test('triage records the actual provider and model that responded', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'Please triage this.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();

    $run = AiRun::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($run->provider)->not->toBeNull()
        ->and($run->model)->not->toBeNull();
});
