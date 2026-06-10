<?php

use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\TicketDepartment;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketSentiment;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('tickets.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can create a ticket with an initial customer message and tags', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('tickets.store'), [
            'subject' => 'Refund request',
            'customer_name' => 'Jane Customer',
            'customer_email' => 'jane@example.com',
            'priority' => TicketPriority::High->value,
            'department' => TicketDepartment::Billing->value,
            'sentiment' => TicketSentiment::Negative->value,
            'initial_message' => 'I need help with a refund.',
            'tags' => ['Refund', 'refund', 'VIP'],
        ]);

    $ticket = Ticket::query()->firstOrFail();

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $ticket));

    expect($ticket->refresh())
        ->user_id->toBe($user->id)
        ->number->toBe('TCK-000001')
        ->status->toBe(TicketStatus::Open)
        ->priority->toBe(TicketPriority::High)
        ->department->toBe(TicketDepartment::Billing)
        ->sentiment->toBe(TicketSentiment::Negative)
        ->closed_at->toBeNull();

    $message = $ticket->messages()->firstOrFail();

    expect($message)
        ->type->toBe(TicketMessageType::CustomerMessage)
        ->body->toBe('I need help with a refund.')
        ->author_name->toBe('Jane Customer')
        ->author_email->toBe('jane@example.com');

    expect(Tag::query()->orderBy('slug')->pluck('slug')->all())->toBe(['refund', 'vip']);
    expect($ticket->tags()->pluck('slug')->all())->toBe(['refund', 'vip']);
});

test('ticket list shows all tickets owned by the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $openTicket = Ticket::factory()->for($user)->create([
        'last_message_at' => now()->addMinutes(3),
    ]);
    $closedTicket = Ticket::factory()->for($user)->closed()->create([
        'last_message_at' => now()->addMinutes(2),
    ]);
    Ticket::factory()->for($otherUser)->create([
        'last_message_at' => now()->addMinute(),
    ]);

    $this->actingAs($user)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Index')
            ->has('tickets.data', 2)
            ->where('tickets.data.0.id', $openTicket->id)
            ->where('tickets.data.1.id', $closedTicket->id)
            ->missing('filters')
            ->missing('options')
            ->missing('tags')
        );
});

test('users can update ticket metadata and close tickets', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $oldTag = Tag::factory()->create(['name' => 'Old', 'slug' => 'old']);
    $ticket->tags()->attach($oldTag);

    $response = $this
        ->actingAs($user)
        ->patch(route('tickets.update', $ticket), [
            'subject' => 'Updated subject',
            'customer_name' => 'Updated Customer',
            'customer_email' => 'updated@example.com',
            'status' => TicketStatus::Closed->value,
            'priority' => TicketPriority::Low->value,
            'department' => TicketDepartment::Technical->value,
            'sentiment' => TicketSentiment::Positive->value,
            'tags' => ['New'],
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $ticket));

    expect($ticket->refresh())
        ->subject->toBe('Updated subject')
        ->status->toBe(TicketStatus::Closed)
        ->priority->toBe(TicketPriority::Low)
        ->department->toBe(TicketDepartment::Technical)
        ->sentiment->toBe(TicketSentiment::Positive)
        ->closed_at->not->toBeNull();

    expect($ticket->tags()->pluck('slug')->all())->toBe(['new']);
});

test('users cannot access tickets owned by another user', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->get(route('tickets.show', $ticket))
        ->assertForbidden();
});

test('deleting a ticket removes messages and tag links but keeps reusable tags', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $message = TicketMessage::factory()->for($ticket)->create();
    $tag = Tag::factory()->create();
    $ticket->tags()->attach($tag);

    $this->actingAs($user)
        ->delete(route('tickets.destroy', $ticket))
        ->assertRedirect(route('tickets.index'));

    $this->assertModelMissing($ticket);
    $this->assertModelMissing($message);
    $this->assertModelExists($tag);
    expect(DB::table('tag_ticket')->count())->toBe(0);
});
