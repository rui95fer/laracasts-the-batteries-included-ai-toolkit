<?php

namespace App\Models;

use App\TicketDepartment;
use App\TicketPriority;
use App\TicketSentiment;
use App\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $number
 * @property string $subject
 * @property string $customer_name
 * @property string $customer_email
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property TicketDepartment $department
 * @property TicketSentiment $sentiment
 * @property Carbon|null $last_message_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'number',
    'subject',
    'customer_name',
    'customer_email',
    'status',
    'priority',
    'department',
    'sentiment',
    'last_message_at',
    'closed_at',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Ticket $ticket): void {
            if ($ticket->number === null) {
                $ticket->forceFill([
                    'number' => sprintf('TCK-%06d', $ticket->id),
                ])->saveQuietly();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereBelongsTo($user);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'department' => TicketDepartment::class,
            'sentiment' => TicketSentiment::class,
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
