<?php

namespace App\Http\Controllers;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    /**
     * Display a listing of the resource.
     */
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

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        Gate::authorize('create', Ticket::class);

        return Inertia::render('tickets/Create', [
            'options' => $this->options(),
            'tags' => $this->tagOptions(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $tagNames = $validated['tags'] ?? [];
        $now = now();

        $ticket = DB::transaction(function () use ($request, $validated, $tagNames, $now): Ticket {
            $ticket = Ticket::create([
                'user_id' => $request->user()->id,
                'subject' => $validated['subject'],
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'status' => TicketStatus::Open,
                'priority' => $validated['priority'],
                'department' => $validated['department'],
                'sentiment' => $validated['sentiment'],
                'last_message_at' => $now,
                'closed_at' => null,
            ]);

            $this->syncTags($ticket, $tagNames);

            $ticket->messages()->create([
                'type' => TicketMessageType::CustomerMessage,
                'body' => $validated['initial_message'],
                'author_name' => $ticket->customer_name,
                'author_email' => $ticket->customer_email,
            ]);

            return $ticket;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket created.')]);

        return to_route('tickets.show', $ticket);
    }

    /**
     * Display the specified resource.
     */
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

    /**
     * Show the form for editing the specified resource.
     */
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

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validated();
        $tagNames = $validated['tags'] ?? [];
        $status = TicketStatus::from($validated['status']);

        DB::transaction(function () use ($ticket, $validated, $tagNames, $status): void {
            $ticket->fill([
                'subject' => $validated['subject'],
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'status' => $status,
                'priority' => $validated['priority'],
                'department' => $validated['department'],
                'sentiment' => $validated['sentiment'],
            ]);

            if ($status === TicketStatus::Closed && $ticket->closed_at === null) {
                $ticket->closed_at = now();
            }

            if ($status !== TicketStatus::Closed) {
                $ticket->closed_at = null;
            }

            $ticket->save();

            $this->syncTags($ticket, $tagNames);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket updated.')]);

        return to_route('tickets.show', $ticket);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ticket $ticket): RedirectResponse
    {
        Gate::authorize('delete', $ticket);

        $ticket->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket deleted.')]);

        return to_route('tickets.index');
    }

    /**
     * @param  array<int, string>  $tagNames
     */
    private function syncTags(Ticket $ticket, array $tagNames): void
    {
        $tagIds = collect($tagNames)
            ->map(function (string $name): ?int {
                $slug = Tag::slugFor($name);

                if ($slug === '') {
                    return null;
                }

                return Tag::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $name]
                )->id;
            })
            ->filter()
            ->values()
            ->all();

        $ticket->tags()->sync($tagIds);
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
