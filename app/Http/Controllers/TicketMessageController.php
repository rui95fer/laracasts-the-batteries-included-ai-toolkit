<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketMessageRequest;
use App\Http\Requests\UpdateTicketMessageRequest;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TicketMessageController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTicketMessageRequest $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validated();
        $type = TicketMessageType::from($validated['type']);
        $now = now();

        DB::transaction(function () use ($request, $ticket, $validated, $type, $now): void {
            $isCustomerMessage = $type === TicketMessageType::CustomerMessage;

            $ticket->messages()->create([
                'user_id' => $isCustomerMessage ? null : $request->user()->id,
                'type' => $type,
                'body' => $validated['body'],
                'author_name' => $isCustomerMessage ? $ticket->customer_name : $request->user()->name,
                'author_email' => $isCustomerMessage ? $ticket->customer_email : $request->user()->email,
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message added.')]);

        return to_route('tickets.show', $ticket);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTicketMessageRequest $request, Ticket $ticket, TicketMessage $message): RedirectResponse
    {
        $message->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message updated.')]);

        return to_route('tickets.show', $ticket);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ticket $ticket, TicketMessage $message): RedirectResponse
    {
        Gate::authorize('delete', $message);

        if ($ticket->messages()->count() === 1) {
            return back()->withErrors(['message' => __('A ticket must keep at least one message.')]);
        }

        DB::transaction(function () use ($ticket, $message): void {
            $message->delete();

            $ticket->last_message_at = $ticket->messages()->latest('created_at')->value('created_at');
            $ticket->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message deleted.')]);

        return to_route('tickets.show', $ticket);
    }
}
