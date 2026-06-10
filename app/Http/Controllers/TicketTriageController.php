<?php

namespace App\Http\Controllers;

use App\Ai\Agents\TicketTriage;
use App\Models\AiRun;
use App\Models\AiUsage;
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
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;
use Throwable;

class TicketTriageController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket): RedirectResponse
    {
        set_time_limit(120);

        Gate::authorize('update', $ticket);

        $latestMessage = $ticket->messages()->latest()->firstOrFail();

        $prompt = "Subject: {$ticket->subject}\nMessage: {$latestMessage->body}";

        $ref = new ReflectionClass(TicketTriage::class);
        $provider = ($ref->getAttributes(ProviderAttribute::class)[0] ?? null)?->newInstance()->value;
        $model = ($ref->getAttributes(ModelAttribute::class)[0] ?? null)?->newInstance()->value;

        $run = AiRun::create([
            'user_id' => $request->user()->id,
            'ticket_id' => $ticket->id,
            'feature' => 'ticket-triage',
            'status' => 'running',
            'provider' => $provider instanceof Lab ? $provider->value : (string) $provider,
            'model' => $model,
            'input_hash' => md5($prompt),
            'started_at' => now(),
        ]);

        try {
            $response = (new TicketTriage)->prompt($prompt);

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

            $run->update([
                'status' => 'succeeded',
                'finished_at' => now(),
            ]);

            AiUsage::create([
                'ai_run_id' => $run->id,
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->promptTokens + $response->usage->completionTokens,
                'cache_write_input_tokens' => $response->usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $response->usage->cacheReadInputTokens,
                'reasoning_tokens' => $response->usage->reasoningTokens,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Ticket triaged.'),
        ]);

        return redirect()->back();
    }
}
