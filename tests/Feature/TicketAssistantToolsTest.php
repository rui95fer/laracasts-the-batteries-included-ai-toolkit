<?php

use App\Ai\Agents\TicketAssistant;
use App\Ai\Tools\TicketFactsTool;
use App\Ai\Tools\TicketMessagesTool;
use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

test('ticket facts tool returns the scoped ticket payload for the owner', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create([
        'subject' => 'Cannot log in',
    ]);
    $ticket->tags()->createMany([
        ['name' => 'auth', 'slug' => 'auth'],
        ['name' => 'urgent', 'slug' => 'urgent'],
    ]);

    $output = (new TicketFactsTool($ticket->id, $user))->handle(new Request);

    $payload = json_decode($output, true);

    expect($payload)
        ->toBeArray()
        ->and($payload['id'])->toBe($ticket->id)
        ->and($payload['subject'])->toBe('Cannot log in')
        ->and($payload['status'])->toBe('open')
        ->and($payload['tags'])->toBe(['auth', 'urgent']);
});

test('ticket facts tool refuses to expose tickets the user cannot view', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $ticket = Ticket::factory()->for($owner)->create();

    $output = (new TicketFactsTool($ticket->id, $intruder))->handle(new Request);

    expect($output)->toBe('unauthorized');
});

test('ticket messages tool returns the most recent messages in chronological order', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    $ticket->messages()->createMany([
        ['type' => TicketMessageType::CustomerMessage, 'body' => 'First',  'author_name' => 'C', 'author_email' => 'c@x.test'],
        ['type' => TicketMessageType::AgentReply,     'body' => 'Second', 'author_name' => 'A', 'author_email' => 'a@x.test'],
        ['type' => TicketMessageType::CustomerMessage, 'body' => 'Third',  'author_name' => 'C', 'author_email' => 'c@x.test'],
    ]);

    $output = (new TicketMessagesTool($ticket->id, $user))->handle(new Request(['count' => 2]));

    $messages = json_decode($output, true);

    expect($messages)
        ->toBeArray()
        ->toHaveCount(2)
        ->and($messages[0]['body'])->toBe('Second')
        ->and($messages[1]['body'])->toBe('Third');
});

test('ticket messages tool clamps the count to the schema range', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    $ticket->messages()->createMany(
        collect(range(1, 6))->map(fn (): array => [
            'type' => TicketMessageType::CustomerMessage,
            'body' => 'm',
            'author_name' => 'C',
            'author_email' => 'c@x.test',
        ])->all()
    );

    $output = (new TicketMessagesTool($ticket->id, $user))->handle(new Request(['count' => 99]));

    $messages = json_decode($output, true);

    expect($messages)->toHaveCount(5);
});

test('ticket messages tool refuses to expose tickets the user cannot view', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $ticket = Ticket::factory()->for($owner)->create();

    $output = (new TicketMessagesTool($ticket->id, $intruder))->handle(new Request(['count' => 3]));

    expect($output)->toBe('unauthorized');
});

test('ticket assistant exposes the scoped facts and messages tools', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    $agent = new TicketAssistant($ticket->id, $user->id);

    $tools = iterator_to_array($agent->tools(), false);

    expect($tools)
        ->toHaveCount(2)
        ->and($tools[0])->toBeInstanceOf(TicketFactsTool::class)
        ->and($tools[0]->ticketId)->toBe($ticket->id)
        ->and($tools[0]->user?->id)->toBe($user->id)
        ->and(ToolNameResolver::resolve($tools[0]))->toBe('ticket_facts')
        ->and($tools[1])->toBeInstanceOf(TicketMessagesTool::class)
        ->and($tools[1]->ticketId)->toBe($ticket->id)
        ->and($tools[1]->user?->id)->toBe($user->id)
        ->and(ToolNameResolver::resolve($tools[1]))->toBe('ticket_messages');
});
