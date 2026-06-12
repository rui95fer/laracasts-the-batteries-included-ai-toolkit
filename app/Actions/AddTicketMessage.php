<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;

class AddTicketMessage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Ticket $ticket, User $user, array $data): void
    {
        $now = now();

        DB::transaction(function () use ($ticket, $user, $data, $now): void {
            $type = TicketMessageType::from($data['type']);
            $isCustomerMessage = $type === TicketMessageType::CustomerMessage;

            $ticket->messages()->create([
                'user_id' => $isCustomerMessage ? null : $user->id,
                'type' => $type,
                'body' => $data['body'],
                'author_name' => $isCustomerMessage ? $ticket->customer_name : $user->name,
                'author_email' => $isCustomerMessage ? $ticket->customer_email : $user->email,
            ]);

            if ($type === TicketMessageType::CustomerMessage) {
                $ticket->status = TicketStatus::Open;
                $ticket->closed_at = null;
            }

            if ($type === TicketMessageType::AgentReply && $ticket->status !== TicketStatus::Closed) {
                $ticket->status = TicketStatus::Pending;
                $ticket->closed_at = null;
            }

            $ticket->last_message_at = $now;
            $ticket->save();
        });
    }
}
