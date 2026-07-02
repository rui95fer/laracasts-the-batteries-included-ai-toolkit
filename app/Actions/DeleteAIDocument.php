<?php

namespace App\Actions;

use App\Models\UploadedDocument;
use App\Models\User;
use Laravel\Ai\Files;
use Laravel\Ai\Stores;

class DeleteAIDocument
{
    public function execute(User $user, UploadedDocument $document): void
    {
        abort_unless($document->user_id === $user->id, 403);

        $store = Stores::get($document->provider_store_id);

        $documentId = $document->provider_document_id ?? $document->provider_file_id;

        $store->remove($documentId);
        Files::delete($document->provider_file_id);

        $document->delete();
    }
}
