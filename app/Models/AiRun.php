<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $ticket_id
 * @property string $feature
 * @property string $status
 * @property string $provider
 * @property string|null $model
 * @property string|null $input_hash
 * @property string|null $started_at
 * @property string|null $finished_at
 * @property string|null $error
 * @property string|null $output_text
 */
#[Fillable([
    'user_id',
    'ticket_id',
    'feature',
    'status',
    'provider',
    'model',
    'input_hash',
    'started_at',
    'finished_at',
    'error',
    'output_text',
])]
class AiRun extends Model
{
    /**
     * @return HasOne<AiUsage, $this>
     */
    public function usage(): HasOne
    {
        return $this->hasOne(AiUsage::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
