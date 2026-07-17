<?php

use App\Ai\Agents\DocumentQAAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Files;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Stores;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/ai-documents-'.uniqid();
    File::makeDirectory($this->tempDir);
});

afterEach(function () {
    if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

test('guests are redirected to the login page on every document route', function () {
    $this->get(route('ai.documents.index'))->assertRedirect(route('login'));
    $this->post(route('ai.documents.store'), [])->assertRedirect(route('login'));
    $this->post(route('ai.documents.ask'), ['question' => 'x'])->assertRedirect(route('login'));
    $this->delete(route('ai.documents.destroy', 1))->assertRedirect(route('login'));
});

test('uploading a document stores the file, creates a store, and adds the file to that store', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('refund.pdf', 4, 'application/pdf');

    Files::fake()->preventStrayOperations();
    Stores::fake([$user->email ?? 'ignored'], files: false)->preventStrayOperations();

    $this->actingAs($user)
        ->post(route('ai.documents.store'), [
            'document' => $file,
        ])
        ->assertRedirect(route('ai.documents.index'));

    $user->refresh();
    expect($user->ai_document_store_id)->not->toBeNull();

    $document = UploadedDocument::query()->where('user_id', $user->id)->firstOrFail();

    expect($document)
        ->file_name->toBe('refund.pdf')
        ->provider_file_id->not->toBe('')
        ->provider_store_id->toBe($user->ai_document_store_id)
        ->and($document->metadata)->toMatchArray([
            'user_id' => (string) $user->id,
            'provider_file_id' => $document->provider_file_id,
        ]);

    Files::assertStored(function ($stored): bool {
        return $stored->name() === 'refund.pdf'
            && $stored->mimeType() === 'application/pdf';
    });

    Stores::assertCreated('Documents for '.$user->email);

    $store = Stores::get($user->ai_document_store_id);

    $store->assertAdded(fn ($file): bool => $file->id() === $document->provider_file_id);
});

test('uploads that are too large or use a disallowed mime type are rejected without touching the provider', function () {
    $user = User::factory()->create();

    Files::fake()->preventStrayOperations();
    Stores::fake([], files: false)->preventStrayOperations();

    $wrongType = UploadedFile::fake()->create('photo.png', 1, 'image/png');
    $this->actingAs($user)
        ->post(route('ai.documents.store'), ['document' => $wrongType])
        ->assertSessionHasErrors('document');

    $tooLarge = UploadedFile::fake()->create('big.pdf', 10240, 'application/pdf');
    $this->actingAs($user)
        ->post(route('ai.documents.store'), ['document' => $tooLarge])
        ->assertSessionHasErrors('document');

    Files::assertNothingStored();
    Stores::assertNothingCreated();
});

test('asking a question logs a run and usage and renders the answer on the index page', function () {
    $user = User::factory()->create();
    UploadedDocument::factory()->forUser($user)->create([
        'provider_store_id' => 'store_test',
    ]);
    $user->forceFill(['ai_document_store_id' => 'store_test'])->save();

    DocumentQAAssistant::fake(['The answer is 14 days.'])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->post(route('ai.documents.ask'), [
            'question' => 'How long for a refund?',
        ]);

    $response->assertRedirect(route('ai.documents.index'));
    $response->assertSessionHas('ai_document_answer', 'The answer is 14 days.');

    $this->get(route('ai.documents.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ai/DocumentQA')
            ->where('storeReady', true)
            ->where('lastAnswer', 'The answer is 14 days.')
        );

    $run = AiRun::query()->where('user_id', $user->id)->firstOrFail();

    expect($run)
        ->feature->toBe('document-qa')
        ->status->toBe('succeeded')
        ->ticket_id->toBeNull()
        ->finished_at->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();

    DocumentQAAssistant::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->prompt === 'How long for a refund?';
    });
});

test('the question is required and capped at two thousand characters and never reaches the agent', function () {
    $user = User::factory()->create();
    $user->forceFill(['ai_document_store_id' => 'store_test'])->save();

    DocumentQAAssistant::fake()->preventStrayPrompts();

    $this->actingAs($user)
        ->post(route('ai.documents.ask'), ['question' => ''])
        ->assertSessionHasErrors('question');

    $this->actingAs($user)
        ->post(route('ai.documents.ask'), ['question' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('question');

    DocumentQAAssistant::assertNeverPrompted();
});

test('asking before any document is uploaded redirects back without creating a run', function () {
    DocumentQAAssistant::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai.documents.ask'), [
            'question' => 'Anything?',
        ])
        ->assertRedirect(route('ai.documents.index'));

    expect(AiRun::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('asking a question falls back to the queue when the sync prompt fails', function () {
    $user = User::factory()->create();
    $user->forceFill(['ai_document_store_id' => 'store_test'])->save();

    DocumentQAAssistant::fake()->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->post(route('ai.documents.ask'), [
            'question' => 'Provider is unavailable right now.',
        ]);

    $response->assertRedirect(route('ai.documents.index'));
    $response->assertSessionHas('ai_document_status', 'queued');

    $run = AiRun::query()->where('user_id', $user->id)->firstOrFail();

    expect($run->feature)->toBe('document-qa')
        ->and($run->status)->toBe('queued')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeFalse();

    DocumentQAAssistant::assertQueued(fn ($queued) => $queued->prompt === 'Provider is unavailable right now.');
});

test('document qa assistant scopes file search by store, user, and selected file', function () {
    $scoped = new DocumentQAAssistant(
        userId: 42,
        storeId: 'store_abc',
        providerFileId: 'file_xyz',
    );
    $unscoped = new DocumentQAAssistant(
        userId: 42,
        storeId: 'store_abc',
    );

    $scopedTools = iterator_to_array($scoped->tools(), false);
    $unscopedTools = iterator_to_array($unscoped->tools(), false);

    expect($scopedTools)->toHaveCount(1)
        ->and($scopedTools[0])->toBeInstanceOf(FileSearch::class)
        ->and($scopedTools[0]->stores)->toBe(['store_abc'])
        ->and($scopedTools[0]->filters)->toBe([
            ['type' => 'eq', 'key' => 'user_id', 'value' => 42],
            ['type' => 'eq', 'key' => 'provider_file_id', 'value' => 'file_xyz'],
        ])
        ->and($unscopedTools[0]->filters)->toBe([
            ['type' => 'eq', 'key' => 'user_id', 'value' => 42],
        ]);
});

test('users cannot delete documents owned by another user', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $document = UploadedDocument::factory()->forUser($owner)->create();

    $this->actingAs($intruder)
        ->delete(route('ai.documents.destroy', $document))
        ->assertForbidden();

    expect(UploadedDocument::query()->whereKey($document->id)->exists())->toBeTrue();
});

test('deleting a document removes it from the store, deletes the provider file, and removes the row', function () {
    $user = User::factory()->create();
    $user->forceFill(['ai_document_store_id' => 'store_test'])->save();

    $document = UploadedDocument::factory()->forUser($user)->create([
        'provider_file_id' => 'file_known',
        'provider_store_id' => 'store_test',
        'provider_document_id' => 'doc_known',
    ]);

    Stores::fake(fn (string $storeId) => $storeId, files: false)->preventStrayOperations();
    Files::fake()->preventStrayOperations();

    $this->actingAs($user)
        ->delete(route('ai.documents.destroy', $document))
        ->assertRedirect(route('ai.documents.index'));

    expect(UploadedDocument::query()->whereKey($document->id)->exists())->toBeFalse();

    $store = Stores::get($document->provider_store_id);

    $store->assertRemoved(fn (string $documentId): bool => $documentId === 'doc_known');

    Files::assertDeleted('file_known');
});
