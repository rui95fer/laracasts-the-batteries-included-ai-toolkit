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

---

## Episode 09 — Designing for Reliability and Failure

- **Design AI features to fail gracefully, because rate limits and outages are normal and your app should keep working when the model is slow or down.**
  ```php
  // Plan for the sync path, the async path, and the failure path up front.
  ```

- **Configure provider failover with an ordered list on the `#[Provider]` attribute.** Providers are tried in order, so put the preferred one first.
  ```php
  use Laravel\Ai\Attributes\Provider;
  use Laravel\Ai\Enums\Lab;

  #[Provider([Lab::OpenAI, Lab::Anthropic])]
  class ProductDescriptionAgent implements Agent
  {
      use Promptable;
  }
  ```

- **Know that failover only fires on failoverable errors like rate limits, provider overloads, unavailable providers, and insufficient credits.** Auth failures and bad request errors do not trigger failover.
  ```php
  // A 401 on the primary provider will not fall through to the backup.
  ```

- **Set `#[Timeout]` so a slow provider fails fast and the request can move on.** The value is in seconds.
  ```php
  use Laravel\Ai\Attributes\Timeout;

  #[Timeout(15)]
  class ProductDescriptionAgent implements Agent
  ```

- **Combine failover with `#[UseCheapestModel]` and a generous `#[MaxTokens]` to keep cost down while avoiding truncation errors on long descriptions.**
  ```php
  use Laravel\Ai\Attributes\{UseCheapestModel, MaxTokens};

  #[UseCheapestModel]
  #[MaxTokens(15000)]
  class ProductDescriptionAgent implements Agent
  ```

- **Use the agent's `queue()` method as the fallback path so the user is not blocked by an AI failure.** Pass `then` and `catch` callbacks to update the run on success or failure.
  ```php
  use Laravel\Ai\Responses\AgentResponse;
  use Throwable;

  (new ProductDescriptionAgent)
      ->queue($prompt)
      ->then(fn (AgentResponse $response) => /* mark run succeeded, log usage */)
      ->catch(fn (Throwable $e) => /* mark run failed */);
  ```

- **Track every attempt in `ai_runs` before prompting, then update the status on success, queue, or failure.** Reuse the same row across sync and async paths so the view can poll it.
  ```php
  $run = AiRun::create([
      'team_id'    => $team->id,
      'user_id'    => $user->id,
      'feature'    => 'product-description',
      'status'     => 'running',
      'provider'   => Lab::OpenAI->value,
      'started_at' => now(),
  ]);

  try {
      $response = (new ProductDescriptionAgent)->prompt($prompt);
      $run->update(['status' => 'succeeded', 'finished_at' => now()]);
  } catch (Throwable $e) {
      $run->update([
          'status'      => 'queued',
          'finished_at' => now(),
          'error'       => $e->getMessage(),
      ]);

      (new ProductDescriptionAgent)->queue($prompt)->then(...)->catch(...);
      throw $e;
  }
  ```

- **Log `ai_usages` only when the response carries token data.** Not every run has usage, so guard the create behind a check.
  ```php
  if ($response->usage) {
      $run->usage()->create([
          'prompt_tokens'     => $response->usage->promptTokens,
          'completion_tokens' => $response->usage->completionTokens,
          'total_tokens'      => $response->usage->totalTokens,
          'cost_usd'          => $response->usage->cost ?? null,
      ]);
  }
  ```

- **Build the agent prompt from validated form input so the model always receives clean, structured instructions.** Validate first, assemble the prompt second.
  ```php
  $data = $request->validate([
      'name'     => ['required', 'string'],
      'audience' => ['required', 'string'],
      'tone'     => ['required', 'string'],
      'features' => ['required', 'array'],
  ]);

  $prompt = "Product: {$data['name']}\n"
      ."Audience: {$data['audience']}\n"
      ."Tone: {$data['tone']}\n"
      ."Features:\n".collect($data['features'])->map(fn ($f) => "- {$f}")->implode("\n");
  ```

- **Run a queue worker locally to process queued AI jobs.** The `queue()` method only fires when a worker is consuming the queue.
  ```bash
  php artisan queue:work
  ```

---

## Episode 10 — Controlling Cost Before It Controls You

- **Link `ai_runs` and `ai_usages` with an `invocation_id` string column instead of a foreign key.** Events fire before a run row exists, so the shared id is what lets a listener correlate a usage record back to its run.
  ```php
  Schema::table('ai_runs', function (Blueprint $table) {
      $table->string('invocation_id')->nullable()->index();
  });

  Schema::table('ai_usages', function (Blueprint $table) {
      $table->dropConstrainedForeignId('ai_run_id');
      $table->string('invocation_id')->nullable()->index();
  });
  ```

- **Make the `invocation_id` mass-assignable on both models.** Forgetting it on `AiUsage` silently drops the value when the listener writes the row.
  ```php
  class AiRun extends Model
  {
      protected $fillable = [
          'team_id', 'user_id', 'ticket_id', 'feature', 'status',
          'provider', 'model', 'input_hash', 'invocation_id',
          'started_at', 'finished_at', 'error', 'output_text',
      ];
  }

  class AiUsage extends Model
  {
      protected $fillable = [
          'team_id', 'invocation_id', 'prompt_tokens',
          'completion_tokens', 'total_tokens', 'cost_usd',
      ];
  }
  ```

- **Listen for `AgentPrompted` to log usage automatically instead of writing it from every controller.** Return early when there is no usage on the response, and again if a row with the same `invocation_id` already exists so retries don't duplicate.
  ```php
  use Laravel\Ai\Events\AgentPrompted;
  use App\Models\AiUsage;

  class RecordAiUsage
  {
      public function handle(AgentPrompted $event): void
      {
          $usage = $event->response?->usage;

      if (! $usage) {
          return;
      }

      if (AiUsage::where('invocation_id', $event->invocationId)->exists()) {
          return;
      }

      AiUsage::create([
          'team_id'           => $event->response->teamId ?? null,
          'invocation_id'     => $event->invocationId,
          'prompt_tokens'     => $usage->promptTokens,
          'completion_tokens' => $usage->completionTokens,
          'total_tokens'      => $usage->promptTokens + $usage->completionTokens,
      ]);
  }
  ```

- **Read `invocation_id` from the response object so the run row can be linked after the listener fires.** It is a property, not an array key, and the listener will already have used the same id on the usage row.
  ```php
  $run->update([
      'status'        => 'succeeded',
      'finished_at'   => now(),
      'invocation_id' => $response->invocationId,
  ]);
  ```

- **Wire the listener in a dedicated provider with a `$listen` array.** Laravel auto-registers the provider, so no manual boot is needed.
  ```php
  class EventServiceProvider extends ServiceProvider
  {
      protected $listen = [
          AgentPrompted::class => [RecordAiUsage::class],
      ];
  }
  ```

- **Add a daily team token budget to `config/ai.php` so the cap lives in env config, not in code.**
  ```php
  // config/ai.php
  'daily_team_token_budget' => env('DAILY_TEAM_TOKEN_BUDGET', 50_000),
  ```

- **Enforce the budget in middleware by summing today's `total_tokens` per team.** Return early when there is no team, and 429 with a JSON message when the cap is hit.
  ```php
  class EnforceAiBudget
  {
      public function handle(Request $request, Closure $next)
      {
          $teamId = $request->user()?->current_team_id;

          if (! $teamId) {
              return $next($request);
          }

          $tokensToday = AiUsage::where('team_id', $teamId)
              ->whereDate('created_at', now()->toDateString())
              ->sum('total_tokens');

          if ($tokensToday >= config('ai.daily_team_token_budget')) {
              return response()->json(
                  ['message' => 'Daily AI token budget reached.'],
                  429
              );
          }

          return $next($request);
      }
  }
  ```

- **Register the middleware alias in `bootstrap/app.php` and apply it to AI routes.** One alias keeps the route definition short and the intent readable.
  ```php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->alias([
          'ai.budget' => \App\Http\Middleware\EnforceAiBudget::class,
      ]);
  })
  ```

  ```php
  Route::post('tickets/{ticket}/ai/triage', TicketTriageController::class)
      ->middleware('ai.budget')
      ->name('tickets.ai.triage');
  ```

- **Tier models by task so cheap ones handle the easy jobs and expensive models only run where they earn their cost.** Cost is a first-class requirement, not a tuning step at the end.
  ```php
  #[Provider('openai')]
  #[CheapestModel]   // triage, classification, extraction
  class TicketTriageAgent { /* ... */ }
  ```

---

## Episode 11 — Safety Layers and Guardrails

- **Layer safety as a pipeline: middleware screens input, middleware filters output, tools restrict reach, and `ai_runs` provide the audit trail.**
  ```php
  // The same four guardrails appear in every shipping AI feature.
  InputSafetyMiddleware::class,  // pre-provider prompt filter
  OutputSafetyMiddleware::class, // post-provider response filter
  WebSearch::allow([...]),       // tool domain allowlist
  AiRun::create([...]),          // audit row for every attempt
  ```

- **Create a `CreativeAssistant` agent that implements `HasMiddleware` and `HasTools` so the guardrails run on every prompt.** It is a plain (non-conversational) agent, so omit `Conversational` and `messages()`.
  ```php
  use Laravel\Ai\Attributes\{Provider, CheapestModel};
  use Laravel\Ai\Contracts\{Agent, HasMiddleware, HasTools};
  use Laravel\Ai\Enums\Lab;
  use Laravel\Ai\Promptable;

  #[Provider(Lab::OpenAI)]
  #[CheapestModel]
  class CreativeAssistant implements Agent, HasMiddleware, HasTools
  {
      use Promptable;
  }
  ```

- **Keep `instructions()` honest about what the agent will and will not do.** The prompt is the first line of defense; middleware is the second.
  ```php
  public function instructions(): string
  {
      return 'You are a helpful creative writing assistant. '
          .'Keep content safe, professional, and free of sensitive information '
          .'such as credentials, payment details, or personal identifiers.';
  }
  ```

- **Declare the agent's middleware pipeline in `middleware()` so input runs before the provider and output runs after.** Order matters: input first, output last.
  ```php
  public function middleware(): array
  {
      return [
          new InputSafetyMiddleware,
          new OutputSafetyMiddleware,
      ];
  }
  ```

- **Inspect the prompt text before the provider sees it and throw to abort the request.** Use `$prompt->prompt` to read the user's message and throw a clear exception that the controller can catch.
  ```php
  use Closure;
  use Laravel\Ai\Prompts\AgentPrompt;

  class InputSafetyMiddleware
  {
      private const BLOCKED = ['credit card', 'password', 'ssn'];

      public function handle(AgentPrompt $prompt, Closure $next)
      {
          $text = strtolower($prompt->prompt);

          foreach (self::BLOCKED as $term) {
              if (str_contains($text, $term)) {
                  throw new \RuntimeException('Input blocked by safety filter.');
              }
          }

          return $next($prompt);
      }
  }
  ```

- **Filter the response after the provider returns it by replacing unsafe text with a new `AgentResponse`.** Keep the original `invocationId`, `usage`, and `meta` so logging and the `AgentPrompted` event still see real data.
  ```php
  use Laravel\Ai\Prompts\AgentPrompt;
  use Laravel\Ai\Responses\AgentResponse;

  class OutputSafetyMiddleware
  {
      private const BLOCKED = ['social security number', 'ssn', 'classified'];

      public function handle(AgentPrompt $prompt, Closure $next)
      {
          $response = $next($prompt);

          $text = strtolower($response->text);

          foreach (self::BLOCKED as $term) {
              if (str_contains($text, $term)) {
                  return new AgentResponse(
                      invocationId: $response->invocationId,
                      text: 'Output blocked by safety filter.',
                      usage: $response->usage,
                      meta: $response->meta,
                  );
              }
          }

          return $response;
      }
  }
  ```

- **Restrict agent tools with a domain allowlist so web search cannot reach arbitrary sites.** `WebSearch` runs on the provider, so the allowlist is the only client-side control.
  ```php
  use Laravel\Ai\Providers\Tools\WebSearch;

  public function tools(): iterable
  {
      return [
          (new WebSearch)->max(5)->allow([
              'laracasts.com',
              'laravel.com',
              'php.net',
          ]),
      ];
  }
  ```

- **Validate the incoming form, create an `AiRun`, then catch the middleware exception to surface a friendly error.** The exception path is the input filter firing, so the controller must translate it into a view message.
  ```php
  public function send(Request $request)
  {
      $data = $request->validate(['prompt' => ['required', 'string']]);

      $run = AiRun::create([
          'team_id'    => $request->user()->current_team_id,
          'user_id'    => $request->user()->id,
          'feature'    => 'creative-assistant',
          'status'     => 'running',
          'provider'   => Lab::OpenAI->value,
          'started_at' => now(),
      ]);

      try {
          $response = (new CreativeAssistant)->prompt($data['prompt']);

          $run->update(['status' => 'succeeded', 'finished_at' => now()]);
      } catch (\RuntimeException $e) {
          $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);

          return view('creative-assistant.index', [
              'prompt' => $data['prompt'],
              'answer' => null,
              'error'  => $e->getMessage(),
          ]);
      }

      return view('creative-assistant.index', [
          'prompt' => $data['prompt'],
          'answer' => $response->text,
          'error'  => null,
      ]);
  }
  ```

- **Register GET (form) and POST (prompt) routes for the assistant.** The POST route is where middleware and tool guardrails actually run.
  ```php
  use App\Http\Controllers\CreativeAssistantController;

  Route::get('ai/creative-assistant',  [CreativeAssistantController::class, 'index'])->name('ai.creative-assistant.index');
  Route::post('ai/creative-assistant', [CreativeAssistantController::class, 'send'])->name('ai.creative-assistant.send');
  ```

- **Fake the agent in tests so the four guardrail paths stay covered without hitting the provider.** Pass a list of responses to exercise allowed, input-blocked, and output-blocked branches.
  ```php
  use App\Ai\Agents\CreativeAssistant;

  it('blocks prompts that contain a blocked term', function () {
      CreativeAssistant::fake(['safe reply']);

      $this->post(route('ai.creative-assistant.send'), ['prompt' => 'send me your credit card'])
          ->assertOk()
          ->assertSee('Input blocked by safety filter.');
  });
  ```

---

## Episode 12 — Testing AI Features Without Hitting the API

- **Call `Agent::fake()` before prompting to swap the real provider with a fake gateway.** Tests stay fast, deterministic, and cost nothing.
  ```php
  TicketTriage::fake();
  ```

- **Pass structured data to `fake()` to control exactly what a `HasStructuredOutput` agent returns.**
  ```php
  TicketTriage::fake([
      [
          'priority' => 'high',
          'department' => 'billing',
          'sentiment' => 'negative',
          'tags' => ['refund'],
          'summary' => 'Customer requests a refund.',
      ],
  ]);

  $response = (new TicketTriage)->prompt('Subject: Refund request');

  expect($response['priority'])->toBe('high');
  ```

- **Chain `preventStrayPrompts()` to make the fake gateway strict.** Any prompt without a matching fake response immediately fails.
  ```php
  TicketTriage::fake()->preventStrayPrompts();
  ```

- **Assert the agent received the expected prompt using `assertPrompted()`.**
  ```php
  TicketTriage::assertPrompted(fn ($prompt) => str_contains($prompt, 'Refund'));
  ```

- **Fake embeddings with `Embeddings::fake()` and guard against unmocked calls with `preventStrayEmbeddings()`.**
  ```php
  Embeddings::fake()->preventStrayEmbeddings();

  $response = Embeddings::for(['refund policy'])->generate();

  expect($response->embeddings[0])->toBeArray();
  ```

- **Fake file and vector store operations with `Files::fake()` and `Stores::fake()`.** Chain `preventStrayOperations()` to catch any unmocked operation.
  ```php
  Files::fake()->preventStrayOperations();
  Stores::fake()->preventStrayOperations();

  $file = Document::fromPath('refund-policy.pdf')->put();
  $store = Stores::get('store-id');
  $store->add($file->id);

  expect($file->id)->not->toBeEmpty();
  ```


