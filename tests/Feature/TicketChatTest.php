<?php

use App\Ai\Agents\TicketAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use Illuminate\Support\Facades\DB;

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

test('user can chat with the assistant and a conversation id is stored', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    TicketAssistant::fake(['AI response text.']);

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), [
            'message' => 'Can you summarise this ticket?',
        ])
        ->assertRedirect();

    $ticket->refresh();

    expect($ticket->ai_conversation_id)->not->toBeNull();

    $run = AiRun::query()->where('ticket_id', $ticket->id)->firstOrFail();
    expect($run->feature)->toBe('ticket-chat')
        ->and($run->status)->toBe('succeeded')
        ->and($run->user_id)->toBe($user->id)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->input_hash)->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();

    $promptMessage = $ticket->messages()
        ->where('type', TicketMessageType::SystemMessage)
        ->where('body', 'AI chat prompt: Can you summarise this ticket?')
        ->first();

    expect($promptMessage)->not->toBeNull()
        ->and($promptMessage->author_name)->toBe($user->name);

    $replyMessage = $ticket->messages()
        ->where('type', TicketMessageType::SystemMessage)
        ->where('body', 'AI chat reply: AI response text.')
        ->first();

    expect($replyMessage)->not->toBeNull()
        ->and($replyMessage->author_name)->toBe('AI Assistant');
});

test('a follow up chat on the same ticket reuses the conversation id', function () {
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

    TicketAssistant::fake();

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), ['message' => ''])
        ->assertSessionHasErrors('message');

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), ['message' => str_repeat('a', 10001)])
        ->assertSessionHasErrors('message');
});

test('deleting a ticket removes its ai conversation and messages', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    TicketAssistant::fake(['Reply.']);

    $this->actingAs($user)
        ->post(route('tickets.ai.chat', $ticket), ['message' => 'Hello.'])
        ->assertRedirect();

    $conversationId = $ticket->refresh()->ai_conversation_id;

    expect($conversationId)->not->toBeNull();

    $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');
    $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');

    expect(DB::table($messagesTable)->where('conversation_id', $conversationId)->count())->toBeGreaterThan(0);
    expect(DB::table($conversationsTable)->where('id', $conversationId)->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('tickets.destroy', $ticket))
        ->assertRedirect(route('tickets.index'));

    $this->assertModelMissing($ticket);
    expect(DB::table($messagesTable)->where('conversation_id', $conversationId)->count())->toBe(0);
    expect(DB::table($conversationsTable)->where('id', $conversationId)->exists())->toBeFalse();
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
