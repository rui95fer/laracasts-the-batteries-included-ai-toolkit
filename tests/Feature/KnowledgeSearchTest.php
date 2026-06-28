<?php

use App\Actions\SearchKnowledgeDocuments;
use App\Models\Document;
use App\Models\User;
use Laravel\Ai\Embeddings;

function queryVector(): array
{
    return [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
}

function parallelVector(float $magnitude): array
{
    $vector = array_fill(0, 8, 0.0);
    $vector[0] = $magnitude;

    return $vector;
}

function perpendicularVector(): array
{
    $vector = array_fill(0, 8, 0.0);
    $vector[1] = 1.0;

    return $vector;
}

beforeEach(function () {
    Embeddings::fake();
});

test('guests are redirected to the login page', function () {
    $this->get(route('ai.knowledge-search'))->assertRedirect(route('login'));
});

test('authenticated users can view the knowledge search page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.knowledge-search'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ai/KnowledgeSearch')
            ->where('query', '')
            ->where('documents', [])
            ->where('minSimilarity', 0.3)
        );
});

test('search returns an empty collection when the query is blank', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->create();

    $results = app(SearchKnowledgeDocuments::class)->execute($user, '   ');

    expect($results)->toBeEmpty();
    Embeddings::assertNothingGenerated();
});

test('search generates and persists missing document embeddings on first use', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'title' => 'Refunds',
        'body' => 'Customers on monthly plans can request a refund within 14 days.',
        'embedding' => null,
    ]);

    Embeddings::fake([
        [queryVector()],
        [parallelVector(1.0)],
    ]);

    $results = app(SearchKnowledgeDocuments::class)->execute($user, 'refund');

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($document->id)
        ->and($document->fresh()->embedding)->toBe([1, 0, 0, 0, 0, 0, 0, 0]);
});

test('search filters out documents below the minimum similarity', function () {
    $user = User::factory()->create();
    $relevant = Document::factory()->for($user)->withEmbedding(parallelVector(1.0))->create();
    Document::factory()->for($user)->withEmbedding(perpendicularVector())->create();

    Embeddings::fake([
        [queryVector()],
    ]);

    $results = app(SearchKnowledgeDocuments::class)->execute($user, 'anything');

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($relevant->id);
});

test('search sorts results by score descending and limits to five', function () {
    $user = User::factory()->create();

    $bestDoc = Document::factory()->for($user)->withEmbedding(parallelVector(1.0))->create();
    $middleDoc = Document::factory()->for($user)->withEmbedding(parallelVector(0.95))->create();
    $worstDoc = Document::factory()->for($user)->withEmbedding(parallelVector(0.9))->create();

    for ($i = 0; $i < 4; $i++) {
        Document::factory()->for($user)->withEmbedding(parallelVector(0.31 + $i * 0.01))->create();
    }

    Embeddings::fake([
        [queryVector()],
    ]);

    $results = app(SearchKnowledgeDocuments::class)->execute($user, 'top hits');

    expect($results->pluck('id')->all())
        ->toBe([
            $bestDoc->id,
            $middleDoc->id,
            $worstDoc->id,
            ...$results->slice(3, 2)->pluck('id')->all(),
        ])
        ->and($results)->toHaveCount(5);
});

test('search only returns documents owned by the requesting user', function () {
    $user = User::factory()->create();
    Document::factory()->for(User::factory())->withEmbedding(parallelVector(1.0))->create();

    Embeddings::fake([
        [queryVector()],
    ]);

    $results = app(SearchKnowledgeDocuments::class)->execute($user, 'refund');

    expect($results)->toBeEmpty();
});

test('search reuses cached document embeddings and skips the embedding call for them', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->withEmbedding(parallelVector(1.0))->create();

    Embeddings::fake([
        [queryVector()],
    ]);

    app(SearchKnowledgeDocuments::class)->execute($user, 'refund');

    Embeddings::assertGenerated(function ($prompt): bool {
        return count($prompt->inputs) === 1;
    });
});
