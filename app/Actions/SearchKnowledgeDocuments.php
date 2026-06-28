<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\User;
use App\Support\Vector;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Ai\Embeddings;

class SearchKnowledgeDocuments
{
    private const MIN_SIMILARITY = 0.3;

    private const MAX_RESULTS = 5;

    /**
     * Search the user's knowledge documents by semantic similarity.
     *
     * The query is embedded via the configured embeddings provider. Any
     * documents in the user's library that do not yet have a stored
     * embedding are generated on the fly and persisted so the next
     * search can skip the API call.
     *
     * @return EloquentCollection<int, Document>
     */
    public function execute(User $user, string $query): EloquentCollection
    {
        $query = trim($query);

        if ($query === '') {
            return new EloquentCollection;
        }

        $documents = Document::query()
            ->ownedBy($user)
            ->get();

        if ($documents->isEmpty()) {
            return new EloquentCollection;
        }

        $queryEmbedding = Embeddings::for([$query])->generate()->embeddings[0];

        $this->ensureDocumentsHaveEmbeddings($documents);

        $scored = $documents
            ->map(function (Document $document) use ($queryEmbedding): array {
                $score = is_array($document->embedding)
                    ? Vector::cosineSimilarity($queryEmbedding, $document->embedding)
                    : 0.0;

                return ['document' => $document, 'score' => $score];
            })
            ->filter(fn (array $result): bool => $result['score'] >= self::MIN_SIMILARITY)
            ->sortByDesc('score')
            ->take(self::MAX_RESULTS)
            ->pluck('document');

        return new EloquentCollection($scored->values()->all());
    }

    public function minSimilarity(): float
    {
        return self::MIN_SIMILARITY;
    }

    /**
     * Persist embeddings for any documents that don't have one yet.
     *
     * @param  EloquentCollection<int, Document>  $documents
     */
    private function ensureDocumentsHaveEmbeddings(EloquentCollection $documents): void
    {
        $missing = $documents->filter(
            fn (Document $document): bool => ! is_array($document->embedding) || $document->embedding === []
        );

        if ($missing->isEmpty()) {
            return;
        }

        $inputs = $missing
            ->map(fn (Document $document): string => $document->title.' '.$document->body)
            ->all();

        $embeddings = Embeddings::for($inputs)->generate()->embeddings;

        foreach ($missing->values() as $index => $document) {
            $document->forceFill(['embedding' => $embeddings[$index]])->save();
        }
    }
}
