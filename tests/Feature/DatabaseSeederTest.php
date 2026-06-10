<?php

use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;

test('database seeder creates sample tickets', function () {
    $this->seed();

    $user = User::query()->where('email', 'rui95fer@gmail.com')->firstOrFail();

    expect($user->tickets()->count())->toBe(10);
    expect(Ticket::query()->whereNull('number')->exists())->toBeFalse();
    expect(Ticket::query()->doesntHave('messages')->exists())->toBeFalse();
    expect(TicketMessage::query()->count())->toBe(10);
    expect(Tag::query()->count())->toBeGreaterThan(0);
});
