<?php

namespace App\Http\Controllers;

use App\Actions\CreateTicket;
use App\Actions\UpdateTicket;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\Tag;
use App\Models\Ticket;
use App\TicketDepartment;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketSentiment;
use App\TicketStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function __construct(
        private readonly CreateTicket $createTicket,
        private readonly UpdateTicket $updateTicket,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Ticket::class);

        $tickets = Ticket::query()
            ->ownedBy($request->user())
            ->with('tags')
            ->withCount('messages')
            ->latest('last_message_at')
            ->latest()
            ->paginate(15)
            ->through(fn (Ticket $ticket): array => $this->ticketPayload($ticket));

        return Inertia::render('tickets/Index', [
            'tickets' => $tickets,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Ticket::class);

        return Inertia::render('tickets/Create', [
            'options' => $this->options(),
            'tags' => $this->tagOptions(),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $ticket = $this->createTicket->execute($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket created.')]);

        return to_route('tickets.show', $ticket);
    }

    public function show(Ticket $ticket): Response
    {
        Gate::authorize('view', $ticket);

        $ticket->load([
            'tags',
            'messages' => fn ($query) => $query->oldest(),
        ]);

        return Inertia::render('tickets/Show', [
            'ticket' => $this->ticketPayload($ticket, includeMessages: true),
            'options' => $this->options(),
        ]);
    }

    public function edit(Ticket $ticket): Response
    {
        Gate::authorize('update', $ticket);

        $ticket->load('tags');

        return Inertia::render('tickets/Edit', [
            'ticket' => $this->ticketPayload($ticket),
            'options' => $this->options(),
            'tags' => $this->tagOptions(),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->updateTicket->execute($ticket, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket updated.')]);

        return to_route('tickets.show', $ticket);
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        Gate::authorize('delete', $ticket);

        $ticket->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket deleted.')]);

        return to_route('tickets.index');
    }

    /**
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function options(): array
    {
        return [
            'statuses' => collect(TicketStatus::cases())->map(fn (TicketStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])->all(),
            'priorities' => collect(TicketPriority::cases())->map(fn (TicketPriority $priority): array => [
                'value' => $priority->value,
                'label' => $priority->label(),
            ])->all(),
            'departments' => collect(TicketDepartment::cases())->map(fn (TicketDepartment $department): array => [
                'value' => $department->value,
                'label' => $department->label(),
            ])->all(),
            'sentiments' => collect(TicketSentiment::cases())->map(fn (TicketSentiment $sentiment): array => [
                'value' => $sentiment->value,
                'label' => $sentiment->label(),
            ])->all(),
            'messageTypes' => collect(TicketMessageType::cases())->map(fn (TicketMessageType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ])->all(),
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string}>
     */
    private function tagOptions(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn (Tag $tag): array => [
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(Ticket $ticket, bool $includeMessages = false): array
    {
        $payload = [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'customer_name' => $ticket->customer_name,
            'customer_email' => $ticket->customer_email,
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->label(),
            'priority' => $ticket->priority?->value,
            'priority_label' => $ticket->priority?->label(),
            'department' => $ticket->department?->value,
            'department_label' => $ticket->department?->label(),
            'sentiment' => $ticket->sentiment?->value,
            'sentiment_label' => $ticket->sentiment?->label(),
            'last_message_at' => $ticket->last_message_at?->toJSON(),
            'closed_at' => $ticket->closed_at?->toJSON(),
            'created_at' => $ticket->created_at?->toJSON(),
            'updated_at' => $ticket->updated_at?->toJSON(),
            'messages_count' => $ticket->messages_count ?? null,
            'tags' => $ticket->tags->map(fn (Tag $tag): array => [
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()->all(),
        ];

        if ($includeMessages) {
            $payload['messages'] = $ticket->messages->map(fn ($message): array => [
                'id' => $message->id,
                'type' => $message->type->value,
                'type_label' => $message->type->label(),
                'body' => $message->body,
                'author_name' => $message->author_name,
                'author_email' => $message->author_email,
                'created_at' => $message->created_at?->toJSON(),
                'updated_at' => $message->updated_at?->toJSON(),
            ])->values()->all();
        }

        return $payload;
    }
}
