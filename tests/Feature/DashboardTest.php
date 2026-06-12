<?php

use App\Models\Ticket;
use App\Models\User;
use App\TicketPriority;
use App\TicketStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows correct ticket counts per status and urgent priority', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Ticket::factory()->for($user)->create(['status' => TicketStatus::Open]);
    Ticket::factory()->for($user)->create(['status' => TicketStatus::Open]);
    Ticket::factory()->for($user)->create(['status' => TicketStatus::Pending]);
    Ticket::factory()->for($user)->closed()->create();
    Ticket::factory()->for($user)->create([
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Urgent,
    ]);
    Ticket::factory()->for($other)->create(['status' => TicketStatus::Open]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('ticketStats.open', 3)
            ->where('ticketStats.pending', 1)
            ->where('ticketStats.closed', 1)
            ->where('ticketStats.urgent', 1)
        );
});
