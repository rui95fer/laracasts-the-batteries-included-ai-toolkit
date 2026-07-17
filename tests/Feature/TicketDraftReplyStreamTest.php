<?php

use App\Ai\Agents\TicketAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;

test('guests are redirected to the login page', function () {
    $ticket = Ticket::factory()->create();

    $this->post(route('tickets.ai.draft-reply.stream', $ticket))
        ->assertRedirect(route('login'));
});

test('users cannot stream a draft reply on tickets owned by another user', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->post(route('tickets.ai.draft-reply.stream', $ticket))
        ->assertForbidden();
});

test('streaming a draft reply emits text deltas, persists the run, and avoids side effects', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    $ticket->messages()->create([
        'type' => TicketMessageType::CustomerMessage,
        'body' => 'I was charged twice for my subscription.',
        'author_name' => $ticket->customer_name,
        'author_email' => $ticket->customer_email,
    ]);

    TicketAssistant::fake(['Thanks for reaching out! Our team will look into it.']);

    $response = $this->actingAs($user)
        ->post(route('tickets.ai.draft-reply.stream', $ticket))
        ->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('"type":"stream_start"')
        ->toContain('"type":"text_start"')
        ->toContain('"type":"text_delta"')
        ->toContain('"delta":"Thanks"')
        ->toContain('"type":"stream_end"')
        ->toEndWith("data: [DONE]\n\n");

    $run = AiRun::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($run)
        ->feature->toBe('ticket-draft-reply')
        ->status->toBe('succeeded')
        ->user_id->toBe($user->id)
        ->finished_at->not->toBeNull()
        ->output_text->toBe('Thanks for reaching out! Our team will look into it.');

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();

    TicketAssistant::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->prompt === 'Draft a concise, friendly reply to the most recent customer message.';
    });

    expect($ticket->messages()->count())->toBe(1);
    expect($ticket->refresh()->ai_conversation_id)->toBeNull();

    $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');

    expect(DB::table($conversationsTable)->count())->toBe(0);
});
