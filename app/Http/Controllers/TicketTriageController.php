<?php

namespace App\Http\Controllers;

use App\Actions\TriageTicket;
use App\Models\AiRun;
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

        $run = $this->triage->execute($ticket);

        Inertia::flash('toast', $this->toastFor($run));

        return redirect()->back();
    }

    /**
     * @return array{type: string, message: string}
     */
    private function toastFor(?AiRun $run): array
    {
        if ($run === null) {
            return ['type' => 'info', 'message' => __('Ticket is already up to date.')];
        }

        return match ($run->status) {
            'queued' => [
                'type' => 'info',
                'message' => __('AI provider is slow or unavailable. Triage is queued for background processing.'),
            ],
            'failed' => [
                'type' => 'error',
                'message' => __('Triage could not be queued. Please try again.'),
            ],
            default => ['type' => 'success', 'message' => __('Ticket triaged.')],
        };
    }
}
