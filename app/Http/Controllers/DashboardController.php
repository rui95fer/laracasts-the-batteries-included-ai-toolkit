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
        $tickets = Ticket::query()->ownedBy($request->user());

        return Inertia::render('Dashboard', [
            'ticketStats' => [
                'open' => (clone $tickets)->where('status', TicketStatus::Open)->count(),
                'pending' => (clone $tickets)->where('status', TicketStatus::Pending)->count(),
                'closed' => (clone $tickets)->where('status', TicketStatus::Closed)->count(),
                'urgent' => (clone $tickets)->where('priority', TicketPriority::Urgent)->count(),
            ],
        ]);
    }
}
