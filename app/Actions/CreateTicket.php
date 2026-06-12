<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;

class CreateTicket
{
    public function __construct(private readonly SyncTicketTags $syncTags) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Ticket
    {
        return DB::transaction(function () use ($user, $data): Ticket {
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'subject' => $data['subject'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'status' => TicketStatus::Open,
                'priority' => $data['priority'],
                'department' => $data['department'],
                'sentiment' => $data['sentiment'],
                'last_message_at' => now(),
                'closed_at' => null,
            ]);

            $this->syncTags->execute($ticket, $data['tags'] ?? []);

            $ticket->messages()->create([
                'type' => TicketMessageType::CustomerMessage,
                'body' => $data['initial_message'],
                'author_name' => $ticket->customer_name,
                'author_email' => $ticket->customer_email,
            ]);

            return $ticket;
        });
    }
}
