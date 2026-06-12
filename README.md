# The Batteries-Included AI Toolkit

Course notes from [Laracasts](https://laracasts.com).

---

## Episode 01 — Structured Output as the Foundation

- **Install the Laravel AI package to build agents with structured output.**
  ```bash
  composer require laravel/ai
  ```

- **Publish the config and run the migration.**
  ```bash
  php artisan vendor:publish --provider="Laravel\AI\AIServiceProvider"
  php artisan migrate
  ```

- **Add your AI provider API key to `.env`.** The `config/ai.php` lists which keys each provider needs.

- **Create an agent class that implements `Agent`, `HasStructuredOutput`, and `Promptable`.** The `Promptable` trait gives you a `prompt()` method; `HasStructuredOutput` forces the model to return data matching your schema.
  ```php
  use Laravel\AI\Agent\Agent;
  use Laravel\AI\Agent\HasStructuredOutput;
  use Laravel\AI\Promptable;

  class TicketTriageAgent implements Agent, HasStructuredOutput
  {
      use Promptable;

      public function instructions(): string
      {
          return 'You are a support ticket triage assistant. Return structured data only. Do not include extra keys.';
      }
  }
  ```

- **Define the expected schema as a property on the agent.** Use typed PHP properties — the SDK reads them to tell the model what shape to return.
  ```php
  public string $priority {
      get => $this->priority;
      set {
          $this->priority = match ($value) {
              'low', 'normal', 'high', 'urgent' => TicketPriority::from($value),
              default => TicketPriority::Normal,
          };
      }
  }

  public string $department;
  public string $sentiment;
  public array $tags;
  public string $summary;
  ```

- **Add rules in `instructions()` to handle edge cases.** Always include every key. Fall back to empty string/array when uncertain.
  ```
  Always include every key in the schema.
  If you cannot determine a value for summary, use an empty string.
  If you cannot determine tags, use an empty array.
  ```

- **Use attributes to configure the provider, model tier, and token limit.**
  ```php
  use Laravel\AI\Attributes\Provider;
  use Laravel\AI\Attributes\CheapestModel;
  use Laravel\AI\Attributes\MaxTokens;

  #[Provider('openai')]
  #[CheapestModel]
  #[MaxTokens(1200)]
  class TicketTriageAgent ...
  ```

- **Create an invocable controller to prompt the agent.** Pass the ticket subject and latest message as context.
  ```php
  class TicketTriageController
  {
      public function __invoke(Ticket $ticket): JsonResponse
      {
          $response = (new TicketTriageAgent)->prompt(
              "Subject: {$ticket->subject}\nMessage: {$ticket->messages()->latest()->first()?->body}"
          );

          $ticket->update([
              'priority' => $response->priority,
              'department' => $response->department,
              'sentiment' => $response->sentiment,
          ]);

          // Sync AI-generated tags
          $ticket->tags()->sync(
              collect($response->tags)->map(fn ($name) => Tag::firstOrCreate(
                  ['slug' => Str::slug($name)], ['name' => $name]
              ))->pluck('id')
          );

          if ($response->summary !== '') {
              $ticket->messages()->create([
                  'type' => TicketMessageType::SystemMessage,
                  'body' => "AI summary: {$response->summary}",
              ]);
          }

          return response()->json(['status' => 'ok', 'data' => $response]);
      }
  }
  ```

- **Register the route and add a "Triage" button on the ticket show page.**
  ```php
  Route::post('tickets/{ticket}/ai/triage', TicketTriageController::class)
      ->name('tickets.ai.triage');
  ```

  ```vue
  <form :action="route('tickets.ai.triage', ticket.id)" method="POST">
      <input type="hidden" name="_token" :value="csrf" />
      <Button type="submit">Triage</Button>
  </form>
  ```

---

## Episode 02 — Logging AI Interactions

- **Track every AI attempt in `ai_runs` and token usage in `ai_usages`.** A run is created before every prompt; usage is logged when the response returns token data (not every run has usage).
  ```php
  Schema::create('ai_runs', function (Blueprint $table) {
      $table->id();
      $table->foreignId('team_id')->constrained()->cascadeOnDelete();
      $table->foreignId('user_id')->constrained();
      $table->foreignId('ticket_id')->constrained()->noActionOnDelete();
      $table->string('feature');           // e.g. 'ticket-triage'
      $table->string('status');            // running, succeeded, failed
      $table->string('provider');
      $table->string('model')->nullable();
      $table->string('input_hash')->nullable();
      $table->dateTime('started_at');
      $table->dateTime('finished_at')->nullable();
      $table->text('error')->nullable();
      $table->timestamps();
  });

  Schema::create('ai_usages', function (Blueprint $table) {
      $table->id();
      $table->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
      $table->unsignedInteger('prompt_tokens')->default(0);
      $table->unsignedInteger('completion_tokens')->default(0);
      $table->unsignedInteger('total_tokens')->default(0);
      $table->decimal('cost_usd', 10, 6)->nullable();
      $table->timestamps();
  });
  ```

- **AiRun has one AiUsage; AiUsage belongs to AiRun.** Not every run produces usage (e.g. errors), but every usage belongs to a run.
  ```php
  class AiRun extends Model
  {
      public function usage(): HasOne
      {
          return $this->hasOne(AiUsage::class);
      }

      public function ticket(): BelongsTo
      {
          return $this->belongsTo(Ticket::class);
      }
  }

  class AiUsage extends Model
  {
      public function run(): BelongsTo
      {
          return $this->belongsTo(AiRun::class, 'ai_run_id');
      }
  }
  ```

- **Create an AiRun before prompting, then update it on success or failure.** On success, record usage from `$response->usage`; on failure, store error and rethrow.
  ```php
  $run = AiRun::create([
      'team_id'   => $ticket->team_id,
      'user_id'   => $request->user()->id,
      'ticket_id' => $ticket->id,
      'feature'   => 'ticket-triage',
      'status'    => 'running',
      'provider'  => 'openai',
      'started_at' => now(),
  ]);

  try {
      $response = (new TicketTriage)->prompt(/* ... */);

      // ... update ticket with response data ...

      $run->update(['status' => 'succeeded', 'finished_at' => now()]);

      if ($response->usage) {
          AiUsage::create([
              'ai_run_id'        => $run->id,
              'prompt_tokens'    => $response->usage->promptTokens,
              'completion_tokens' => $response->usage->completionTokens,
              'total_tokens'     => $response->usage->totalTokens,
              'cost_usd'         => $response->usage->cost ?? null,
          ]);
      }
  } catch (Throwable $e) {
      $run->update([
          'status'      => 'failed',
          'finished_at' => now(),
          'error'       => $e->getMessage(),
      ]);

      throw $e;
  }
  ```

---

## Episode 03 — Scoping Conversation Memory

- **Bind chat memory to a single ticket with an `ai_conversation_id` column.** Prevents one ticket's history from leaking into another and keeps token usage predictable.
  ```php
  Schema::table('tickets', function (Blueprint $table) {
      $table->string('ai_conversation_id')->nullable()->after('ai_tags');
  });
  ```

- **Make a conversational `TicketAssistant` agent that auto-stores history.** Implement `Conversational` and use `RemembersConversations` so the SDK persists messages for you.
  ```php
  use Laravel\Ai\Attributes\{Provider, UseCheapestModel, MaxTokens};
  use Laravel\Ai\Concerns\RemembersConversations;
  use Laravel\Ai\Contracts\{Agent, Conversational};
  use Laravel\Ai\Enums\Lab;
  use Laravel\Ai\Promptable;

  #[Provider(Lab::OpenAI)]
  #[UseCheapestModel]
  #[MaxTokens(1500)]
  class TicketAssistant implements Agent, Conversational
  {
      use Promptable, RemembersConversations;

      public function __construct(public readonly int $ticketId) {}
  }
  ```

- **Stuff the prompt with the ticket's context, not the whole DB.** Concatenate status, priority, department, sentiment, tags, and the last few messages into the instructions so the model has what it needs.
  ```php
  public function instructions(): string
  {
      $context = $this->ticketContext();

      return "You are a support assistant. Stay strictly within the current ticket. "
          ."If you are unsure, ask a clarifying question.\n\n"
          .$context;
  }
  ```

- **Build a compact context string from related ticket data.** Eager-load the most recent messages and tags, then format them as one block.
  ```php
  private function ticketContext(): string
  {
      $ticket = Ticket::with(['tags', 'messages' => fn ($q) => $q->latest()->limit(5)])
          ->find($this->ticketId);

      if (! $ticket) {
          return 'Ticket context unavailable.';
      }

      $tags = $ticket->tags->pluck('name')->implode(', ') ?: 'none';
      $messages = $ticket->messages->reverse()->map(
          fn ($m) => "{$m->role}: {$m->body}"
      )->implode("\n");

      return "Subject: {$ticket->subject}\n"
          ."Status: {$ticket->status->value}\n"
          ."Priority: {$ticket->priority->value}\n"
          ."Department: {$ticket->department?->value ?? 'n/a'}\n"
          ."Sentiment: {$ticket->sentiment?->value ?? 'n/a'}\n"
          ."Tags: {$tags}\n"
          ."Recent messages:\n{$messages}";
  }
  ```

- **Branch on whether the ticket already has a conversation id.** Use `forUser()` to start fresh, `continue()` to resume — both scope history to the signed-in user.
  ```php
  $agent = (new TicketAssistant($ticket->id))->forUser($request->user());

  $response = $ticket->ai_conversation_id
      ? $agent->continue($ticket->ai_conversation_id, as: $request->user())->prompt($prompt)
      : $agent->prompt($prompt);

  $ticket->update(['ai_conversation_id' => $response->conversationId]);
  ```

- **Log chat runs and usage with a dedicated `feature` key.** Reuse the `AiRun` / `AiUsage` pattern from episode 02 so chat and triage are distinguishable in the logs.
  ```php
  $run = AiRun::create([
      'team_id'   => $ticket->team_id,
      'user_id'   => $request->user()->id,
      'ticket_id' => $ticket->id,
      'feature'   => 'ticket-chat',
      'status'    => 'running',
      'provider'  => Lab::OpenAI->value,
      'started_at' => now(),
  ]);
  ```

- **Persist the user and assistant turns as ticket messages.** Each side of the exchange becomes a row, mirroring how the AI summary was stored in episode 01.
  ```php
  $ticket->messages()->create([
      'user_id' => $request->user()->id,
      'role'    => 'user',
      'body'    => $request->input('message'),
  ]);

  $ticket->messages()->create([
      'role' => 'agent',
      'body' => $response->text,
  ]);
  ```

- **Register the chat endpoint alongside triage.** One route, named for Wayfinder, so the frontend can target it by name.
  ```php
  Route::post('tickets/{ticket}/ai/chat', TicketChatController::class)
      ->name('tickets.ai.chat');
  ```

---

## Episode 04 — Demoing Our Chat Agent

- **Render the CSRF token in a meta tag so JavaScript can read it.** Use it to send Laravel's `X-CSRF-TOKEN` header on every `POST` from the page.
  ```blade
  <meta name="csrf-token" content="{{ csrf_token() }}">
  ```

- **Move reusable agent calls into a service when the same logic is needed in more than one UI.** Keep controllers thin and avoid duplicating prompt handling across Livewire, controllers, or Inertia pages.
  ```php
  // Before — agent call living inside a controller
  $response = (new TicketAssistant($ticket->id))->forUser($user)->prompt($message);

  // After — service the controller, Livewire, and tests all share
  $response = app(TicketChatService::class)->ask($ticket, $user, $message);
  ```

- **Wrap demo UI in a small Alpine component scoped to the ticket.** Localize state (`ticketId`, `prompt`, `response`) inside `x-data` so it doesn't leak into the page.
  ```html
  <div x-data="ticketChatDemo({ ticketId: {{ $ticket->id }} })">
      <!-- prompt, response, send form -->
  </div>
  ```

- **Support an optional `initialResponse` so the demo can render pre-seeded text.** Useful for tests and for replaying a previous reply without re-running the agent.
  ```js
  ticketChatDemo({ ticketId, initialResponse = '' }) {
      return {
          ticketId,
          prompt: '',
          response: initialResponse,
          async send() { /* ... */ },
      }
  }
  ```

- **Ignore empty or too-short messages before hitting the network.** Trim, validate length, clear the prompt on send, then return early to avoid wasted calls.
  ```js
  const message = this.prompt.trim()
  if (message.length < 3) return
  this.prompt = ''
  ```

- **Send the chat prompt as JSON to the ticket-scoped AI chat endpoint.** Include the `X-CSRF-TOKEN` header so Laravel accepts the request from JavaScript.
  ```js
  const res = await fetch(`/tickets/${this.ticketId}/ai/chat`, {
      method: 'POST',
      headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
      body: JSON.stringify({ message }),
  })
  ```

- **Throw on non-OK responses so the `catch` can log a single error path.** Use the server-provided `data.message` when available, then fall back to a generic message.
  ```js
  const data = await res.json().catch(() => ({}))

  if (! res.ok) {
      throw new Error(data.message ?? 'Request failed')
  }

  this.response = data.message ?? ''
  ```

- **Bind the response and prompt with `x-text` and `x-model` in the demo markup.** A `<form @submit.prevent="send">` keeps Enter-to-send working without page reloads.
  ```html
  <form @submit.prevent="send">
      <div x-text="response"></div>
      <textarea x-model="prompt"></textarea>
      <button type="submit">Send</button>
  </form>
  ```

- **Note: this demo returns the full response in one shot.** The next episode will stream tokens so replies appear progressively, like a real chat.

