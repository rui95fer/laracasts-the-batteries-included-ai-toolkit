<?php

namespace App\Http\Controllers;

use App\Actions\DraftTicketReply;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Responses\StreamableAgentResponse;

class TicketDraftReplyStreamController extends Controller
{
    public function __construct(
        private readonly DraftTicketReply $draftReply,
    ) {}

    public function __invoke(Request $request, Ticket $ticket): StreamableAgentResponse
    {
        Gate::authorize('update', $ticket);

        return $this->draftReply->execute($ticket, $request->user());
    }
}
