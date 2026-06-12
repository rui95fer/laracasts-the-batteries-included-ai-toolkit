<?php

namespace App\Http\Controllers;

use App\Actions\TriageTicket;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TicketTriageController extends Controller
{
    public function __construct(private readonly TriageTicket $triage) {}

    public function __invoke(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('update', $ticket);

        $this->triage->execute($ticket);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Ticket triaged.'),
        ]);

        return redirect()->back();
    }
}
