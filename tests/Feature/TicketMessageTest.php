<?php

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\TicketMessageType;
use App\TicketStatus;

test('customer messages reopen closed tickets and update activity', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->closed()->create([
        'customer_name' => 'Jane Customer',
        'customer_email' => 'jane@example.com',
    ]);

    $this->actingAs($user)
        ->post(route('tickets.messages.store', $ticket), [
            'type' => TicketMessageType::CustomerMessage->value,
            'body' => 'Any update?',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $ticket));

    $message = $ticket->messages()->firstOrFail();

    expect($ticket->refresh())
        ->status->toBe(TicketStatus::Open)
        ->closed_at->toBeNull()
        ->last_message_at->not->toBeNull();

    expect($message)
        ->type->toBe(TicketMessageType::CustomerMessage)
        ->author_name->toBe('Jane Customer')
        ->author_email->toBe('jane@example.com');
});

test('agent replies move active tickets to pending but cannot be added to closed tickets', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $closedTicket = Ticket::factory()->for($user)->closed()->create();

    $this->actingAs($user)
        ->post(route('tickets.messages.store', $ticket), [
            'type' => TicketMessageType::AgentReply->value,
            'body' => 'I replied by email.',
        ])
        ->assertSessionHasNoErrors();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending);

    $this->actingAs($user)
        ->from(route('tickets.show', $closedTicket))
        ->post(route('tickets.messages.store', $closedTicket), [
            'type' => TicketMessageType::AgentReply->value,
            'body' => 'Reply on a closed ticket.',
        ])
        ->assertSessionHasErrors('type')
        ->assertRedirect(route('tickets.show', $closedTicket));
});

test('internal notes do not change ticket status', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->pending()->create();

    $this->actingAs($user)
        ->post(route('tickets.messages.store', $ticket), [
            'type' => TicketMessageType::InternalNote->value,
            'body' => 'Need to check billing logs.',
        ])
        ->assertSessionHasNoErrors();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending);
});

test('messages can be edited and deleted but the final message is protected', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $firstMessage = TicketMessage::factory()->for($ticket)->create(['created_at' => now()->subHour()]);
    $secondMessage = TicketMessage::factory()->for($ticket)->internalNote()->create(['created_at' => now()]);
    $ticket->forceFill(['last_message_at' => $secondMessage->created_at])->save();

    $this->actingAs($user)
        ->patch(route('tickets.messages.update', [$ticket, $secondMessage]), [
            'body' => 'Updated note.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $ticket));

    expect($secondMessage->refresh()->body)->toBe('Updated note.');

    $this->actingAs($user)
        ->delete(route('tickets.messages.destroy', [$ticket, $secondMessage]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $ticket));

    $this->assertModelMissing($secondMessage);
    expect($ticket->refresh()->last_message_at->toJSON())->toBe($firstMessage->created_at->toJSON());

    $this->actingAs($user)
        ->delete(route('tickets.messages.destroy', [$ticket, $firstMessage]))
        ->assertSessionHasErrors('message');

    $this->assertModelExists($firstMessage);
});

test('users cannot update messages on tickets owned by another user', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for(User::factory())->create();
    $message = TicketMessage::factory()->for($ticket)->create();

    $this->actingAs($user)
        ->patch(route('tickets.messages.update', [$ticket, $message]), [
            'body' => 'Unauthorized edit.',
        ])
        ->assertForbidden();
});
