<?php

namespace App\Http\Controllers;

use App\Actions\SearchKnowledgeDocuments;
use App\Http\Requests\SearchKnowledgeRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeSearchController extends Controller
{
    public function __construct(
        private readonly SearchKnowledgeDocuments $search,
    ) {}

    public function __invoke(SearchKnowledgeRequest $request): Response|RedirectResponse
    {
        $query = (string) $request->input('q', '');

        $documents = $this->search->execute($request->user(), $query);

        $payload = $documents->map(fn ($document): array => [
            'id' => $document->id,
            'title' => $document->title,
            'body' => $document->body,
            'excerpt' => str($document->body)->limit(180)->toString(),
        ])->values();

        return Inertia::render('ai/KnowledgeSearch', [
            'query' => $query,
            'documents' => $payload,
            'minSimilarity' => $this->search->minSimilarity(),
        ]);
    }
}
