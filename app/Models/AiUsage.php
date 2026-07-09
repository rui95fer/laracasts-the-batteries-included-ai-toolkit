<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $ai_run_id
 * @property string|null $invocation_id
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property int $cache_write_input_tokens
 * @property int $cache_read_input_tokens
 * @property int $reasoning_tokens
 */
#[Fillable([
    'ai_run_id',
    'invocation_id',
    'prompt_tokens',
    'completion_tokens',
    'total_tokens',
    'cache_write_input_tokens',
    'cache_read_input_tokens',
    'reasoning_tokens',
])]
class AiUsage extends Model
{
    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
