<?php

namespace App\Ai\Middleware;

use App\Ai\Exceptions\InputBlockedBySafetyFilter;
use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;

class InputSafetyMiddleware
{
    /**
     * Lower-cased terms that may not appear in a user prompt.
     *
     * @var list<string>
     */
    private const BLOCKED_TERMS = [
        'credit card',
        'ssn',
        'social security',
        'password',
        'api key',
        'secret key',
        'classified',
    ];

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $text = Str::lower($prompt->prompt);

        foreach (self::BLOCKED_TERMS as $term) {
            if (Str::contains($text, $term)) {
                throw new InputBlockedBySafetyFilter($term);
            }
        }

        return $next($prompt);
    }
}
