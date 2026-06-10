<?php

namespace App\Http\Controllers;

use App\Ai\Agents\TicketTriage;
use App\Models\Tag;
use App\Models\Ticket;
use App\TicketDepartment;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketSentiment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TicketTriageController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket): RedirectResponse
    {
        set_time_limit(120);

        Gate::authorize('update', $ticket);

        $latestMessage = $ticket->messages()->latest()->firstOrFail();

        $response = (new TicketTriage)->prompt(
            "Subject: {$ticket->subject}\nMessage: {$latestMessage->body}"
        );

        DB::transaction(function () use ($ticket, $response): void {
            $ticket->update([
                'priority' => TicketPriority::from($response['priority']),
                'department' => TicketDepartment::from($response['department']),
                'sentiment' => TicketSentiment::from($response['sentiment']),
            ]);

            $tagIds = collect($response['tags'])
                ->map(function (string $name): ?int {
                    $slug = Tag::slugFor($name);

                    if ($slug === '') {
                        return null;
                    }

                    return Tag::firstOrCreate(
                        ['slug' => $slug],
                        ['name' => Str::title(str_replace('-', ' ', $name))]
                    )->id;
                })
                ->filter()
                ->values()
                ->all();

            $ticket->tags()->sync($tagIds);

            if (filled($response['summary'])) {
                $ticket->messages()->create([
                    'type' => TicketMessageType::SystemMessage,
                    'body' => "AI summary: {$response['summary']}",
                    'author_name' => 'System',
                    'author_email' => 'system@localhost',
                ]);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Ticket triaged.'),
        ]);

        return redirect()->back();
    }
}
