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

---

## Episode 05 — Streaming AI Responses to the UI

- **Stream AI responses to remove the "blank screen" wait.** Even a fast model feels slow if the user stares at nothing — progressive tokens make the interaction feel instant.
  ```php
  return (new TicketAssistant($ticket->id))->forUser($user)
      ->stream('Draft a concise, friendly reply to the most recent user message.');
  ```

- **Persist the final streamed output on the run.** Add a nullable `output_text` column to `ai_runs` so every interaction, streamed or not, keeps a single audit record.
  ```php
  Schema::table('ai_runs', function (Blueprint $table) {
      $table->text('output_text')->nullable()->after('error');
  });
  ```

- **Register the new column on the model.** Without this, `AiRun` won't accept the field from mass-assignment.
  ```php
  #[Fillable([..., 'error', 'output_text'])]
  class AiRun extends Model { /* ... */ }
  ```

- **Create an invocable controller dedicated to streaming.** Make it `__invoke` so the route maps a single action, then reuse the existing `TicketAssistant` agent.
  ```php
  class TicketDraftReplyStreamController
  {
      public function __invoke(Request $request, Ticket $ticket): \Symfony\Component\HttpFoundation\StreamedResponse
      {
          $agent = (new TicketAssistant($ticket->id))->forUser($request->user());
          $prompt = 'Draft a concise, friendly reply to the most recent user message.';

          $run = AiRun::create([
              'team_id'   => $ticket->team_id,
              'user_id'   => $request->user()->id,
              'ticket_id' => $ticket->id,
              'feature'   => 'draft-reply',
              'status'    => 'running',
              'provider'  => Lab::OpenAI->value,
              'started_at'=> now(),
          ]);

          return $agent->stream($prompt, function (StreamedAgentResponse $response) use ($run) {
              $run->update([
                  'status'      => 'succeeded',
                  'finished_at' => now(),
                  'model'       => $response->meta->model,
                  'output_text' => $response->text,
              ]);

              if ($response->usage) {
                  $run->usage()->create([
                      'prompt_tokens'    => $response->usage->promptTokens,
                      'completion_tokens' => $response->usage->completionTokens,
                      'total_tokens'     => $response->usage->totalTokens,
                      'cost_usd'         => $response->usage->cost ?? null,
                  ]);
              }
          });
      }
  }
  ```

- **Register the streaming route alongside the chat route.** A separate URL lets the chat demo stay non-streaming while the draft reply streams.
  ```php
  Route::post('tickets/{ticket}/ai/draft-reply/stream', TicketDraftReplyStreamController::class)
      ->name('tickets.ai.draft-reply.stream');
  ```

- **Build a small Alpine component to drive the stream.** Localize `ticketId`, `draft`, and an `AbortController` so you can cancel mid-stream.
  ```html
  <div x-data="ticketDraftReplyDemo({ ticketId: {{ $ticket->id }}, initialDraft: '' })">
      <textarea data-ticket-reply x-model="draft"></textarea>
      <button @click="streamDraft()">Draft reply</button>
      <button @click="cancel()">Cancel</button>
      <button @click="insertIntoReply()">Insert into reply</button>
  </div>
  ```

- **Request the stream with `Accept: text/event-stream` and an abort signal.** The browser exposes streams via `fetch`; the signal lets you cancel cleanly.
  ```js
  streamDraft() {
      this.draft = ''
      this.controller = new AbortController()

      return fetch(`/tickets/${this.ticketId}/ai/draft-reply/stream`, {
          method: 'POST',
          headers: {
              'Accept': 'text/event-stream',
              'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          signal: this.controller.signal,
      })
  },
  ```

- **Read the SSE body with a `ReadableStream` reader, a `TextDecoder`, and a buffer.** SSE events are separated by two newlines; incomplete chunks must be kept until the next read.
  ```js
  const reader = res.body.getReader()
  const decoder = new TextDecoder()
  let buffer = ''

  while (true) {
      const { value, done } = await reader.read()
      if (done) break

      buffer += decoder.decode(value, { stream: true })
      const parts = buffer.split('\n\n')
      buffer = parts.pop() // keep the last (possibly incomplete) chunk
  }
  ```

- **Parse `text_delta` events and append them to the draft.** Ignore any non-`data:` preambles; bail out when the server sends `data: [DONE]`.
  ```js
  for (const part of parts) {
      if (! part.startsWith('data:')) continue
      const payload = part.replace(/^data:\s*/, '').trim()
      if (payload === '[DONE]') return

      const event = JSON.parse(payload)
      if (event.type === 'text_delta') {
          this.draft += event.delta
      }
  }
  ```

- **Abort the stream on demand and copy the draft into the reply box.** A single `AbortController` call cancels the in-flight `fetch`.
  ```js
  cancel() {
      this.controller?.abort()
  },

  insertIntoReply() {
      document.querySelector('[data-ticket-reply]').value = this.draft
  },
  ```

---

## Episode 06 — Giving AI the Ability to Use Tools

- **LLMs are great at words but bad at facts — give your agent tools with strict input schemas plus authorization inside each tool so the model can only read what you explicitly allow.**
  ```php
  // A tool is a small, scoped gateway between the model and your data.
  ```

- **Scaffold a tool with `php artisan make:tool`; it lands in `app/Ai/Tools`.**
  ```bash
  php artisan make:tool TicketFactsTool
  php artisan make:tool TicketMessagesTool
  ```

- **Scope a tool to the data it should see by accepting `ticketId` and `user` through the constructor so calls can't bleed across records.**
  ```php
  public function __construct(
      public readonly int $ticketId,
      public readonly ?User $user = null,
  ) {}
  ```

- **Describe what the tool returns in `description()` so the model knows when to call it.**
  ```php
  public function description(): string
  {
      return 'Fetches the key facts about the current ticket: status, priority, department, sentiment, tags.';
  }
  ```

- **Return an empty `schema()` when the tool needs no input from the model — only the scoped data you already injected.**
  ```php
  public function schema(JsonSchema $schema): array
  {
      return [];
  }
  ```

- **Constrain the inputs the model must provide in `schema()` with type, range, and `required()`.**
  ```php
  public function schema(JsonSchema $schema): array
  {
      return [
          'count' => $schema->integer()->min(1)->max(5)->required(),
      ];
  }
  ```

- **Authorize inside `handle()` — fall back to the signed-in user, then return `'unauthorized'` if the caller or ticket can't be resolved.**
  ```php
  $user ??= $this->user ?? auth()->user();

  if (! $user) {
      return 'unauthorized';
  }

  $ticket = Ticket::with('tags')->find($this->ticketId);

  if (! $ticket) {
      return 'unauthorized';
  }
  ```

- **Return compact JSON (not a model) so the model can read the payload directly.**
  ```php
  return json_encode([
      'id'         => $ticket->id,
      'subject'    => $ticket->subject,
      'status'     => $ticket->status->value,
      'priority'   => $ticket->priority->value,
      'department' => $ticket->department?->value,
      'sentiment'  => $ticket->sentiment?->value,
      'tags'       => $ticket->tags->pluck('name')->all(),
  ], JSON_PRETTY_PRINT);
  ```

- **Clamp model-provided inputs to the range declared in `schema()` before querying.**
  ```php
  $count = max(1, min(5, $request->integer('count', 3)));
  ```

- **Return the most recent N messages, reversed, so the model sees them in chronological order.**
  ```php
  return $ticket->messages()
      ->latest()
      ->limit($count)
      ->get()
      ->reverse()
      ->map(fn ($m) => ['role' => $m->role, 'body' => $m->body])
      ->values()
      ->toJson(JSON_PRETTY_PRINT);
  ```

- **Expose tools to an agent by implementing `HasTools` and returning them from `tools()`.**
  ```php
  use Laravel\Ai\Contracts\HasTools;

  class TicketAssistant implements Agent, Conversational, HasTools
  {
      public function tools(): iterable
      {
          $user = $this->userId ? User::find($this->userId) : null;

          return [
              new TicketFactsTool($this->ticketId, $user),
              new TicketMessagesTool($this->ticketId, $user),
          ];
      }
  }
  ```

- **Pass the authenticated `userId` into the agent when constructing it so its tools can scope every call.**
  ```php
  $agent = (new TicketAssistant($ticket->id, $request->user()->id))->forUser($request->user());
  ```

- **Cap tool chaining with `#[MaxSteps]` and bump `#[MaxTokens]` — tool-using runs need more room and shouldn't loop forever.**
  ```php
  #[MaxSteps(3)]
  #[MaxTokens(5000)]
  class TicketAssistant implements Agent, Conversational, HasTools
  ```

- **The model decides when to call a tool — your prompt and the tool's `description()` are what steer that choice, and you can verify the call in the provider's request logs.**
  ```php
  // Asking "what were the last two messages?" makes the model call TicketMessagesTool with count=2.
  ```

---

## Episode 07 — Introducing RAG Basics

- **RAG retrieves relevant data, augments the prompt, and generates a grounded answer.** It keeps responses tied to your data instead of the model's memory.
  ```php
  $results = $this->search($query);                        // retrieve
  $response = $agent->prompt($this->context($results));    // augment + generate
  ```

- **Embeddings are numeric fingerprints used for semantic search.** Similar text produces vectors that are close together; dissimilar text is far apart.
  ```php
  $response = Embeddings::for([$query])->generate();
  $queryVector = $response->embeddings[0];
  ```

- **A vector column is the production storage for embeddings.** SQLite has no vector type, so a `json` column works for demos; PostgreSQL with `pgvector` scales.
  ```php
  // SQLite demo
  $table->json('embedding')->nullable();

  // Production Postgres
  $table->vector('embedding', dimensions: 1536)->index();
  ```

- **Cosine similarity returns `0.0` to `1.0`; closer to `1` is more similar.** Even a near-exact match rarely crosses `0.7` because the full document differs from a short query.
  ```php
  $score = $this->cosineSimilarity($queryVector, $documentVector);
  ```

- **Generate and persist document embeddings lazily.** Caching the vector on the row skips the API call on every subsequent search.
  ```php
  if (! is_array($document->embedding)) {
      $document->update([
          'embedding' => Embeddings::for([$title.' '.$body])->generate()->embeddings[0],
      ]);
  }
  ```

- **Pick a low `min_similarity` because real queries rarely match exactly.** A threshold around `0.3` is a reasonable starting point for natural-language questions against documents.
  ```php
  $minSimilarity = 0.3;
  ```

- **Score every document, filter, sort by score desc, then take the top N.** Keeping the result set small keeps the prompt focused.
  ```php
  $results = $documents
      ->map(fn ($doc) => ['doc' => $doc, 'score' => $this->cosineSimilarity($queryVector, $doc->embedding)])
      ->filter(fn ($r) => $r['score'] >= $minSimilarity)
      ->sortByDesc('score')
      ->take(5)
      ->values();
  ```

- **Scope the search to the current team so users only see their own documents.** Always filter by the active `team_id` from the request.
  ```php
  $teamId = $request->user()->currentTeam->id;
  $documents = Document::where('team_id', $teamId)->get();
  ```

- **Wire the search behind a single GET route and a sidebar link.** Return the query, scored results, and threshold so the view can render them.
  ```php
  Route::get('ai/knowledge-search', KnowledgeSearchController::class)
      ->name('ai.knowledge-search');
  ```

---

## Episode 08 — Switching to Vector Stores

- **Let the provider manage embeddings and search so user-uploaded documents scale beyond a demo.** Hand the heavy lifting off so you don't need a special column type or a similarity routine.
  ```php
  $file = Document::fromPath('refund.pdf')->put();
  $store = Stores::create('Knowledge Base');
  $store->add($file->id);
  ```

- **Store the provider's IDs in your database — the provider is the system of record.** Without them you cannot fetch, search, or delete the file later.
  ```php
  Schema::create('uploaded_documents', function (Blueprint $table) {
      $table->id();
      $table->foreignId('team_id')->constrained()->cascadeOnDelete();
      $table->foreignId('user_id')->constrained();
      $table->string('file_name');
      $table->string('provider_file_id');   // find or delete the file
      $table->string('provider_store_id');  // search the file
      $table->json('metadata')->nullable(); // strict-scope filters
      $table->timestamps();
  });
  ```

- **Cast `metadata` as an array and wire up `team` and `user` on the model.**
  ```php
  class UploadedDocument extends Model
  {
      protected $fillable = [
          'team_id', 'user_id', 'file_name',
          'provider_file_id', 'provider_store_id', 'metadata',
      ];

      protected function casts(): array
      {
          return ['metadata' => 'array'];
      }

      public function team(): BelongsTo { return $this->belongsTo(Team::class); }
      public function user(): BelongsTo { return $this->belongsTo(User::class); }
  }
  ```

- **Use the smartest model, more tokens, and `MaxSteps` for a document Q&A agent.** Indexing and searching benefits from a stronger model, and tool-using runs need more room.
  ```php
  use Laravel\Ai\Attributes\{Provider, MaxSteps, MaxTokens, UseSmartestModel};
  use Laravel\Ai\Enums\Lab;

  #[Provider(Lab::OpenAI)]
  #[UseSmartestModel]
  #[MaxTokens(1600)]
  #[MaxSteps(3)]
  class DocumentQAAssistant implements Agent
  {
      use Promptable;
  }
  ```

- **Inject the team, user, store, and optional provider file into the agent so every search is scoped.**
  ```php
  public function __construct(
      public readonly int $teamId,
      public readonly int $userId,
      public readonly string $storeId,
      public ?string $providerFileId = null,
  ) {}
  ```

- **Tell the assistant to only answer from the uploaded documents and cite its sources.** This keeps responses grounded and stops the model from inventing answers.
  ```php
  public function instructions(): string
  {
      return 'You are a support documentation assistant. '
          .'Only answer using the uploaded documents available through the file search tool. '
          .'If the answer is not in the document(s), say you do not know. '
          .'After the answer, include a short sources list with file names.';
  }
  ```

- **Use `FileSearch` to let the model retrieve chunks without running embeddings yourself.** The provider handles chunking, embedding, and similarity; you just declare the store and scope the query.
  ```php
  use Laravel\Ai\Providers\Tools\FileSearch;

  public function tools(): iterable
  {
      return [
          new FileSearch(
              stores: [$this->storeId],
              callback: function ($query) {
                  $query->where('team_id', $this->teamId)
                      ->where('user_id', $this->userId);

                  if ($this->providerFileId) {
                      $query->where('provider_file_id', $this->providerFileId);
                  }
              },
          ),
      ];
  }
  ```

- **Wrap an uploaded file with `Document::fromUpload()` and put it through `Files::put()`.** The SDK serializes the file using the provider's file API so you don't manage the upload yourself.
  ```php
  use Laravel\Ai\Files\Document as AiDocument;

  $document = AiDocument::fromUpload($request->file('document'));
  $file = Files::put($document); // stored on the provider
  ```

- **Add the file to the store, then persist the row with the provider IDs.** One upload produces three records: the provider file, the store membership, and your DB row.
  ```php
  $store->add($file->id);

  UploadedDocument::create([
      'team_id'           => $team->id,
      'user_id'           => $user->id,
      'file_name'         => $request->file('document')->getClientOriginalName(),
      'provider_file_id'  => $file->id,
      'provider_store_id' => $store->id,
  ]);
  ```

- **Ask the agent inside an `AiRun` try/catch so failures are auditable.** Log the run, record usage on success, and surface the error on failure like other features.
  ```php
  $run = AiRun::create([
      'team_id' => $team->id, 'user_id' => $user->id,
      'feature' => 'document-qa', 'status' => 'running',
      'provider' => Lab::OpenAI->value, 'started_at' => now(),
  ]);

  try {
      $response = (new DocumentQAAssistant(
          teamId: $team->id, userId: $user->id,
          storeId: $storeId, providerFileId: $providerFileId,
      ))->prompt($request->input('question'));

      $run->update(['status' => 'succeeded', 'finished_at' => now()]);

      if ($response->usage) {
          $run->usage()->create([
              'prompt_tokens'    => $response->usage->promptTokens,
              'completion_tokens'=> $response->usage->completionTokens,
              'total_tokens'     => $response->usage->totalTokens,
              'cost_usd'         => $response->usage->cost ?? null,
          ]);
      }
  } catch (Throwable $e) {
      $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
      throw $e;
  }
  ```

- **Delete in the right order: store membership, then provider file, then your row.** Reversing the order leaves orphaned data on the provider.
  ```php
  $store = Stores::get($document->provider_store_id);
  $store->remove($document->provider_file_id);
  Files::delete($document->provider_file_id);
  $document->delete();
  ```

- **Resolve or create one vector store per team so uploads are scoped automatically.** Cache the store ID on the team to avoid recreating it on every upload.
  ```php
  $store = $team->provider_store_id
      ? Stores::get($team->provider_store_id)
      : tap(Stores::create("Team {$team->id}"), fn ($s) => $team->update(['provider_store_id' => $s->id]));
  ```

- **Normalize sources from the tool results so the view can render file names and scores.** Filter out anything that isn't the file search tool, then flatten the results.
  ```php
  private function normalizeSources(iterable $toolResults): array
  {
      return collect($toolResults)
          ->filter(fn ($r) => $r->name === 'file_search')
          ->flatMap(fn ($r) => $r->results)
          ->map(fn ($r) => ['file' => $r->fileName, 'score' => $r->score])
          ->all();
  }
  ```

- **Register four routes for upload, ask, and delete, then add a sidebar link to the view.** Keep the action methods small and let the controller compose the agent call.
  ```php
  Route::prefix('ai/documents')->name('ai.documents.')->group(function () {
      Route::get('/',  [AIDocumentQAController::class, 'index'])->name('index');
      Route::post('/', [AIDocumentQAController::class, 'store'])->name('store');
      Route::post('/ask', [AIDocumentQAController::class, 'ask'])->name('ask');
      Route::delete('/{document}', [AIDocumentQAController::class, 'destroy'])->name('destroy');
  });
  ```

