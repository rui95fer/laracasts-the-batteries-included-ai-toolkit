<?php

namespace App\Http\Controllers;

use App\Actions\AddTicketMessage;
use App\Actions\DeleteTicketMessage;
use App\Http\Requests\StoreTicketMessageRequest;
use App\Http\Requests\UpdateTicketMessageRequest;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TicketMessageController extends Controller
{
    public function __construct(
        private readonly AddTicketMessage $addMessage,
        private readonly DeleteTicketMessage $deleteMessage,
    ) {}

    public function store(StoreTicketMessageRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->addMessage->execute($ticket, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message added.')]);

        return to_route('tickets.show', $ticket);
    }

    public function update(UpdateTicketMessageRequest $request, Ticket $ticket, TicketMessage $message): RedirectResponse
    {
        $message->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message updated.')]);

        return to_route('tickets.show', $ticket);
    }

    public function destroy(Ticket $ticket, TicketMessage $message): RedirectResponse
    {
        Gate::authorize('delete', $message);

        $deleted = $this->deleteMessage->execute($ticket, $message);

        if (! $deleted) {
            return back()->withErrors(['message' => __('A ticket must keep at least one message.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message deleted.')]);

        return to_route('tickets.show', $ticket);
    }
}
