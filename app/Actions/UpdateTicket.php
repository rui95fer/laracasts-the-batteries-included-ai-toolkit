<?php

namespace App\Actions;

use App\Models\Ticket;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;

class UpdateTicket
{
    public function __construct(private readonly SyncTicketTags $syncTags) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Ticket $ticket, array $data): Ticket
    {
        $closedAt = now();

        return DB::transaction(function () use ($ticket, $data, $closedAt): Ticket {
            $status = TicketStatus::from($data['status']);

            $ticket->fill([
                'subject' => $data['subject'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'status' => $status,
                'priority' => $data['priority'],
                'department' => $data['department'],
                'sentiment' => $data['sentiment'],
            ]);

            if ($status === TicketStatus::Closed && $ticket->closed_at === null) {
                $ticket->closed_at = $closedAt;
            }

            if ($status !== TicketStatus::Closed) {
                $ticket->closed_at = null;
            }

            $ticket->save();

            $this->syncTags->execute($ticket, $data['tags'] ?? []);

            return $ticket;
        });
    }
}
