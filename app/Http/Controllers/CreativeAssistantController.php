<?php

namespace App\Http\Controllers;

use App\Actions\PromptCreativeAssistant;
use App\Http\Requests\PromptCreativeAssistantRequest;
use App\Models\AiRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreativeAssistantController extends Controller
{
    public function __construct(
        private readonly PromptCreativeAssistant $prompt,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('ai/CreativeAssistant', [
            'lastPrompt' => session('ai_creative_assistant_prompt'),
            'lastAnswer' => session('ai_creative_assistant_answer'),
            'lastStatus' => session('ai_creative_assistant_status'),
            'pendingAiRun' => $this->pendingAiRunPayload($user),
        ]);
    }

    public function store(PromptCreativeAssistantRequest $request): RedirectResponse
    {
        $prompt = $request->validated('prompt');

        $result = $this->prompt->execute($request->user(), $prompt);

        $redirect = to_route('ai.creative-assistant.index')
            ->with('ai_creative_assistant_prompt', $prompt)
            ->with('ai_creative_assistant_status', $result['status']);

        match ($result['status']) {
            'succeeded' => $redirect->with('ai_creative_assistant_answer', $result['answer']),
            'blocked' => $redirect->withErrors(['prompt' => 'Input blocked by safety filter.']),
            'queued' => $redirect->with('toast', [
                'type' => 'info',
                'message' => __('AI provider is slow or unavailable. Answer is queued for background processing.'),
            ]),
            default => $redirect->with('toast', [
                'type' => 'error',
                'message' => __('The creative assistant could not answer right now.'),
            ]),
        };

        return $redirect;
    }

    /**
     * @return array{id: int, status: string}|null
     */
    private function pendingAiRunPayload($user): ?array
    {
        $run = AiRun::query()
            ->where('user_id', $user->id)
            ->where('feature', 'creative-assistant')
            ->whereIn('status', ['running', 'queued'])
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
        ];
    }
}
