<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\TicketPriority;
use App\TicketStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $stats = Ticket::query()
            ->ownedBy($request->user())
            ->selectRaw('
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed_count,
                SUM(CASE WHEN priority = ? THEN 1 ELSE 0 END) as urgent_count
            ', [
                TicketStatus::Open->value,
                TicketStatus::Pending->value,
                TicketStatus::Closed->value,
                TicketPriority::Urgent->value,
            ])
            ->first();

        return Inertia::render('Dashboard', [
            'ticketStats' => [
                'open' => (int) ($stats->open_count ?? 0),
                'pending' => (int) ($stats->pending_count ?? 0),
                'closed' => (int) ($stats->closed_count ?? 0),
                'urgent' => (int) ($stats->urgent_count ?? 0),
            ],
        ]);
    }
}
