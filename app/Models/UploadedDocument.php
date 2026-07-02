<?php

namespace App\Models;

use Database\Factories\UploadedDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $file_name
 * @property string $provider_file_id
 * @property string $provider_store_id
 * @property string|null $provider_document_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UploadedDocument extends Model
{
    /** @use HasFactory<UploadedDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'file_name',
        'provider_file_id',
        'provider_store_id',
        'provider_document_id',
        'metadata',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<UploadedDocument>  $query
     * @return Builder<UploadedDocument>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereBelongsTo($user);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
