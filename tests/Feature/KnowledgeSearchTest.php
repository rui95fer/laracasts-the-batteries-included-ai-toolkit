<?php

use App\Actions\SearchKnowledgeDocuments;
use App\Models\Document;
use App\Models\User;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

function queryVector(): array
{
    return [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
}

function matchingVector(): array
{
    return [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
}

function closeVector(): array
{
    return [0.95, 0.31, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
}

function farVector(): array
{
    return [0.5, 0.87, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
}

function perpendicularVector(): array
{
    return [0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
}

test('guests are redirected to the login page', function () {
    $this->get(route('ai.knowledge-search'))->assertRedirect(route('login'));
});

test('a blank query returns no results and never calls the embeddings provider', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->create();

    Embeddings::fake()->preventStrayEmbeddings();

    $this->actingAs($user)
        ->get(route('ai.knowledge-search'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ai/KnowledgeSearch')
            ->where('query', '')
            ->where('documents', [])
            ->where('minSimilarity', 0.3)
        );

    Embeddings::assertNothingGenerated();
});

test('the query is validated and capped at two hundred fifty five characters', function () {
    $user = User::factory()->create();

    Embeddings::fake()->preventStrayEmbeddings();

    $this->actingAs($user)
        ->get(route('ai.knowledge-search', ['q' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('q');

    Embeddings::assertNothingGenerated();
});

test('semantic search embeds the query, filters, ranks, and limits results', function () {
    $user = User::factory()->create();

    $matching = Document::factory()->for($user)->withEmbedding(matchingVector())->create();
    $close = Document::factory()->for($user)->withEmbedding(closeVector())->create();
    $far = Document::factory()->for($user)->withEmbedding(farVector())->create();
    Document::factory()->for($user)->withEmbedding(matchingVector())->create();
    Document::factory()->for($user)->withEmbedding(closeVector())->create();
    Document::factory()->for($user)->withEmbedding(farVector())->create();
    Document::factory()->for($user)->withEmbedding(perpendicularVector())->create();
    Document::factory()->for($user)->withEmbedding(perpendicularVector())->create();

    Embeddings::fake([
        [queryVector()],
    ])->preventStrayEmbeddings();

    $results = app(SearchKnowledgeDocuments::class)->execute($user, 'refund');

    expect($results)->toHaveCount(5)
        ->and($results->first()->id)->toBe($matching->id);

    Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt): bool {
        return $prompt->inputs === ['refund'];
    });
});

test('search only returns documents owned by the requesting user', function () {
    $user = User::factory()->create();
    Document::factory()->for(User::factory())->withEmbedding(matchingVector())->create();
    $own = Document::factory()->for($user)->withEmbedding(matchingVector())->create();

    Embeddings::fake([[queryVector()]])->preventStrayEmbeddings();

    $results = app(SearchKnowledgeDocuments::class)->execute($user, 'refund');

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($own->id);
});
