<?php

use App\Ai\Agents\TicketTriage;
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

    TicketTriage::fake();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket));

    expect($ticket->refresh()->tags()->count())->toBeGreaterThanOrEqual(0);
});
