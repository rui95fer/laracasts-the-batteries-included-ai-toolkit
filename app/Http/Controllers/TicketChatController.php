<?php

namespace App\Http\Controllers;

use App\Actions\ChatWithTicketAssistant;
use App\Http\Requests\StoreTicketChatRequest;
use App\Models\AiRun;
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
        $run = $this->chat->execute(
            $ticket,
            $request->user(),
            $request->validated('message'),
        );

        Inertia::flash('toast', $this->toastFor($run));

        return redirect()->back();
    }

    /**
     * @return array{type: string, message: string}
     */
    private function toastFor(?AiRun $run): array
    {
        if ($run === null) {
            return ['type' => 'info', 'message' => __('Another AI chat is already running.')];
        }

        return match ($run->status) {
            'queued' => [
                'type' => 'info',
                'message' => __('AI provider is slow or unavailable. Reply is queued for background processing.'),
            ],
            'failed' => [
                'type' => 'error',
                'message' => __('AI reply could not be queued. Please try again.'),
            ],
            default => ['type' => 'success', 'message' => __('AI assistant replied.')],
        };
    }
}
