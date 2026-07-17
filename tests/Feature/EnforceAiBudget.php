<?php

use App\Ai\Agents\TicketTriage;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;

test('users under the daily budget can triage and are not affected by other users usage', function () {
    config(['ai.daily_user_token_budget' => 100]);

    $other = User::factory()->create();
    $otherTicket = Ticket::factory()->for($other)->create();
    $otherTicket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'Other user message.',
        'author_name' => $otherTicket->customer_name,
        'author_email' => $otherTicket->customer_email,
    ]);

    $otherRun = AiRun::create([
        'user_id' => $other->id,
        'ticket_id' => $otherTicket->id,
        'feature' => 'ticket-triage',
        'status' => 'succeeded',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'invocation_id' => 'inv-other',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    AiUsage::create([
        'ai_run_id' => $otherRun->id,
        'invocation_id' => 'inv-other',
        'prompt_tokens' => 50,
        'completion_tokens' => 60,
        'cache_write_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'total_tokens' => 110,
    ]);

    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'My own message.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketTriage::fake();

    $this->actingAs($user)
        ->post(route('tickets.ai.triage', $ticket))
        ->assertRedirect();
});

test('users at or over the daily budget are blocked with a 429', function () {
    config(['ai.daily_user_token_budget' => 100]);

    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'I was charged twice.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    $run = AiRun::create([
        'user_id' => $user->id,
        'ticket_id' => $ticket->id,
        'feature' => 'ticket-triage',
        'status' => 'succeeded',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'invocation_id' => 'inv-budgeted',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    AiUsage::create([
        'ai_run_id' => $run->id,
        'invocation_id' => 'inv-budgeted',
        'prompt_tokens' => 50,
        'completion_tokens' => 60,
        'cache_write_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'total_tokens' => 110,
    ]);

    TicketTriage::fake();

    $this->actingAs($user)
        ->postJson(route('tickets.ai.triage', $ticket))
        ->assertStatus(429)
        ->assertJson(['message' => 'Daily AI token budget reached.']);
});
