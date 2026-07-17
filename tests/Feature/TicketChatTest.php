<?php

use App\Ai\Agents\TicketAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use Laravel\Ai\Prompts\AgentPrompt;

test('guests are redirected to the login page', function () {
    $ticket = Ticket::factory()->create();

    $this->post(route('tickets.ai.chat', $ticket), [
        'message' => 'Hello assistant.',
    ])->assertRedirect(route('login'));
});

test('users cannot chat on tickets owned by another user', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), [
            'message' => 'Hello assistant.',
        ])
        ->assertForbidden();
});

test('user can chat with the assistant and follow up on the same conversation', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    TicketAssistant::fake([
        'First reply.',
        'Second reply.',
    ]);

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), ['message' => 'First prompt.'])
        ->assertRedirect();

    $firstConversationId = $ticket->refresh()->ai_conversation_id;

    expect($firstConversationId)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), ['message' => 'Second prompt.'])
        ->assertRedirect();

    expect($ticket->refresh()->ai_conversation_id)->toBe($firstConversationId);

    $run = AiRun::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
    expect($run->feature)->toBe('ticket-chat')
        ->and($run->status)->toBe('succeeded')
        ->and($run->user_id)->toBe($user->id)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->input_hash)->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();

    TicketAssistant::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->prompt === 'Second prompt.';
    });

    $chatMessages = $ticket->messages()
        ->where('type', TicketMessageType::SystemMessage)
        ->get();

    expect($chatMessages)->toHaveCount(4)
        ->and($chatMessages->pluck('body')->all())->toBe([
            'AI chat prompt: First prompt.',
            'AI chat reply: First reply.',
            'AI chat prompt: Second prompt.',
            'AI chat reply: Second reply.',
        ]);
});

test('two tickets owned by the same user get separate conversations', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = User::factory()->create();
    $firstTicket = Ticket::factory()->for($user)->create();
    $secondTicket = Ticket::factory()->for($user)->create();

    TicketAssistant::fake(['Reply one.', 'Reply two.']);

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $firstTicket), ['message' => 'First ticket.'])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $secondTicket), ['message' => 'Second ticket.'])
        ->assertRedirect();

    expect($firstTicket->refresh()->ai_conversation_id)
        ->not->toBeNull()
        ->not->toBe($secondTicket->refresh()->ai_conversation_id);
});

test('message is required and capped at ten thousand characters', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    TicketAssistant::fake()->preventStrayPrompts();

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), ['message' => ''])
        ->assertSessionHasErrors('message');

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), ['message' => str_repeat('a', 10001)])
        ->assertSessionHasErrors('message');

    TicketAssistant::assertNeverPrompted();
});

test('chat falls back to the queue when the sync prompt fails and saves the user prompt immediately', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    TicketAssistant::fake()->preventStrayPrompts();

    $this->actingAs($user)
        ->from(route('tickets.show', $ticket))
        ->post(route('tickets.ai.chat', $ticket), [
            'message' => 'Provider is unavailable right now.',
        ])
        ->assertRedirect(route('tickets.show', $ticket));

    $run = AiRun::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($run->feature)->toBe('ticket-chat')
        ->and($run->status)->toBe('queued')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->not->toBeNull();

    expect($ticket->refresh()->messages()->where('body', 'AI chat prompt: Provider is unavailable right now.')->exists())->toBeTrue();

    TicketAssistant::assertQueued(fn ($queued) => $queued->prompt === 'Provider is unavailable right now.');
});
