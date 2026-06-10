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

