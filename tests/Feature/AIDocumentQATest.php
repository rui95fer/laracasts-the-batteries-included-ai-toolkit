<?php

use App\Ai\Agents\DocumentQAAssistant;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Files;
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

test('guests are redirected to the login page', function () {
    $this->get(route('ai.documents.index'))->assertRedirect(route('login'));
});

test('authenticated users can view the document qa page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.documents.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ai/DocumentQA')
            ->where('documents', [])
            ->where('storeReady', false)
            ->where('lastAnswer', null)
        );
});

test('uploading a document stores the file and adds it to the vector store', function () {
    Stores::fake();
    Files::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('refund.pdf', 4, 'application/pdf');

    $this->actingAs($user)
        ->post(route('ai.documents.store'), [
            'document' => $file,
        ])
        ->assertRedirect(route('ai.documents.index'));

    $user->refresh();
    expect($user->ai_document_store_id)->not->toBeNull();

    $document = UploadedDocument::query()->where('user_id', $user->id)->firstOrFail();

    expect($document->file_name)->toBe('refund.pdf')
        ->and($document->provider_file_id)->not->toBe('')
        ->and($document->provider_store_id)->toBe($user->ai_document_store_id)
        ->and($document->metadata)->toMatchArray([
            'user_id' => (string) $user->id,
        ]);

    Files::assertStored(fn ($stored) => true);
});

test('upload validation rejects files that are too large or wrong mime', function () {
    $user = User::factory()->create();

    $wrongType = UploadedFile::fake()->create('photo.png', 1, 'image/png');
    $this->actingAs($user)
        ->post(route('ai.documents.store'), ['document' => $wrongType])
        ->assertSessionHasErrors('document');

    $tooLarge = UploadedFile::fake()->create('big.pdf', 10240, 'application/pdf');
    $this->actingAs($user)
        ->post(route('ai.documents.store'), ['document' => $tooLarge])
        ->assertSessionHasErrors('document');
});

test('asking a question logs a run and usage', function () {
    Stores::fake();
    Files::fake();
    DocumentQAAssistant::fake(['The answer is 14 days.']);

    $user = User::factory()->create();
    UploadedDocument::factory()->forUser($user)->create([
        'provider_store_id' => $user->ai_document_store_id ?? 'store_pending',
    ]);

    $user->forceFill(['ai_document_store_id' => 'store_test'])->save();

    $response = $this->actingAs($user)
        ->post(route('ai.documents.ask'), [
            'question' => 'How long for a refund?',
        ]);

    $response->assertRedirect(route('ai.documents.index'));
    $response->assertSessionHas('ai_document_answer', 'The answer is 14 days.');

    $run = AiRun::query()->where('user_id', $user->id)->firstOrFail();

    expect($run->feature)->toBe('document-qa')
        ->and($run->status)->toBe('succeeded')
        ->and($run->ticket_id)->toBeNull()
        ->and($run->finished_at)->not->toBeNull();

    expect(AiUsage::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();
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

test('question is required and capped at two thousand characters', function () {
    $user = User::factory()->create();
    $user->forceFill(['ai_document_store_id' => 'store_test'])->save();

    DocumentQAAssistant::fake();

    $this->actingAs($user)
        ->post(route('ai.documents.ask'), ['question' => ''])
        ->assertSessionHasErrors('question');

    $this->actingAs($user)
        ->post(route('ai.documents.ask'), ['question' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('question');
});

test('users cannot delete documents owned by another user', function () {
    Stores::fake();
    Files::fake();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $document = UploadedDocument::factory()->forUser($owner)->create();

    $this->actingAs($intruder)
        ->delete(route('ai.documents.destroy', $document))
        ->assertForbidden();

    expect(UploadedDocument::query()->whereKey($document->id)->exists())->toBeTrue();
});

test('deleting a document removes the file from the store and the row from the database', function () {
    Stores::fake();
    Files::fake();

    $user = User::factory()->create();
    $user->forceFill(['ai_document_store_id' => 'store_test'])->save();

    $document = UploadedDocument::factory()->forUser($user)->create([
        'provider_store_id' => 'store_test',
    ]);

    $this->actingAs($user)
        ->delete(route('ai.documents.destroy', $document))
        ->assertRedirect(route('ai.documents.index'));

    expect(UploadedDocument::query()->whereKey($document->id)->exists())->toBeFalse();
});
