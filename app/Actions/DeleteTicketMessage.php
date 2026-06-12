<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class DeleteTicketMessage
{
    /**
     * Delete a ticket message while preserving at least one message on the ticket.
     *
     * Returns true when the message was deleted, false when the deletion was
     * blocked to enforce the "at least one message per ticket" invariant.
     */
    public function execute(Ticket $ticket, TicketMessage $message): bool
    {
        return DB::transaction(function () use ($ticket, $message): bool {
            Ticket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->first();

            if ($ticket->messages()->count() <= 1) {
                return false;
            }

            $message->delete();

            $ticket->last_message_at = $ticket->messages()->latest('created_at')->value('created_at');
            $ticket->save();

            return true;
        });
    }
}
