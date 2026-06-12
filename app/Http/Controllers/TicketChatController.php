<?php

namespace App\Http\Controllers;

use App\Actions\ChatWithTicketAssistant;
use App\Http\Requests\StoreTicketChatRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TicketChatController extends Controller
{
    public function __construct(
        private readonly ChatWithTicketAssistant $chat,
    ) {}

    public function __invoke(StoreTicketChatRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->chat->execute(
            $ticket,
            $request->user(),
            $request->validated('message'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('AI assistant replied.'),
        ]);

        return redirect()->back();
    }
}
