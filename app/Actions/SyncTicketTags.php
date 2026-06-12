<?php

namespace App\Actions;

use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class SyncTicketTags
{
    private const MAX_SLUG_LENGTH = 40;

    /**
     * Normalize, resolve, and sync the given tag names to a ticket.
     *
     * @param  array<int, string>|Collection<int, string>  $tagNames
     */
    public function execute(Ticket $ticket, array|Collection $tagNames): void
    {
        $tagIds = collect($tagNames)
            ->map(fn (mixed $name): ?int => is_string($name) ? $this->resolveTagId($name) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $ticket->tags()->sync($tagIds);
    }

    private function resolveTagId(string $name): ?int
    {
        $slug = Tag::slugFor($name);

        if ($slug === '' || mb_strlen($slug) > self::MAX_SLUG_LENGTH) {
            return null;
        }

        $tag = Tag::query()->where('slug', $slug)->first();

        if ($tag !== null) {
            return $tag->id;
        }

        try {
            return Tag::query()->create([
                'name' => Str::title(str_replace('-', ' ', $name)),
                'slug' => $slug,
            ])->id;
        } catch (Throwable) {
            return Tag::query()->where('slug', $slug)->value('id');
        }
    }
}
