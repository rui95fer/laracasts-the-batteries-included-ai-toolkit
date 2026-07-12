<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\InputSafetyMiddleware;
use App\Ai\Middleware\OutputSafetyMiddleware;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;
use Tool;

#[Provider(Lab::OpenAI)]
#[UseCheapestModel]
#[MaxTokens(800)]
#[MaxSteps(3)]
#[Timeout(60)]
class CreativeAssistant implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    /**
     * Domains the model is allowed to read when the web search tool is used.
     *
     * @var list<string>
     */
    private const ALLOWED_DOMAINS = [
        'laracasts.com',
        'laravel.com',
        'php.net',
    ];

    public function instructions(): Stringable|string
    {
        return 'You are a helpful creative writing assistant. '
            .'Keep content safe, professional, and free of sensitive information '
            .'such as credentials, payment details, or personal identifiers. '
            .'If a request would expose that information, refuse and suggest a safe alternative.';
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new InputSafetyMiddleware,
            new OutputSafetyMiddleware,
        ];
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            (new WebSearch)
                ->max(5)
                ->allow(self::ALLOWED_DOMAINS),
        ];
    }
}
