<?php

namespace App\Http\Middleware;

use App\Models\AiUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAiBudget
{
    /**
     * Block AI requests once the authenticated user has spent the daily
     * token budget configured at `config('ai.daily_user_token_budget')`.
     *
     * Unauthenticated requests pass through so the framework can render
     * the standard login redirect. A missing or zero budget disables
     * the check, which is useful in tests and local development.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $budget = (int) config('ai.daily_user_token_budget', 0);

        if ($budget <= 0) {
            return $next($request);
        }

        $tokensToday = AiUsage::query()
            ->whereHas('run', fn ($query) => $query->where('user_id', $user->getKey()))
            ->whereDate('created_at', now()->toDateString())
            ->sum('total_tokens');

        if ($tokensToday >= $budget) {
            return response()->json(
                ['message' => 'Daily AI token budget reached.'],
                429,
            );
        }

        return $next($request);
    }
}
