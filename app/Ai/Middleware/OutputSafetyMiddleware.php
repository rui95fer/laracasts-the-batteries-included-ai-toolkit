<?php

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class OutputSafetyMiddleware
{
    /**
     * Lower-cased terms that must not appear in the model's response.
     *
     * @var list<string>
     */
    private const BLOCKED_TERMS = [
        'social security number',
        'ssn',
        'credit card number',
        'classified',
    ];

    public const SAFE_REPLY = 'Output blocked by safety filter.';

    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        $response = $next($prompt);

        $text = Str::lower($response->text);

        foreach (self::BLOCKED_TERMS as $term) {
            if (Str::contains($text, $term)) {
                $response->text = self::SAFE_REPLY;

                return $response;
            }
        }

        return $response;
    }
}
