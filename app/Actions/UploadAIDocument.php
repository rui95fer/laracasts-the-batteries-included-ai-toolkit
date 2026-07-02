<?php

namespace App\Actions;

use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document as AiDocument;
use Laravel\Ai\Store;
use Laravel\Ai\Stores;

class UploadAIDocument
{
    public function execute(User $user, UploadedFile $file): UploadedDocument
    {
        return DB::transaction(function () use ($user, $file): UploadedDocument {
            $stored = Files::put(AiDocument::fromUpload($file));
            $store = $this->resolveStore($user);
            $metadata = [
                'user_id' => (string) $user->id,
                'provider_file_id' => $stored->id,
            ];

            $added = $store->add(
                AiDocument::fromId($stored->id),
                metadata: $metadata,
            );

            return UploadedDocument::create([
                'user_id' => $user->id,
                'file_name' => $file->getClientOriginalName(),
                'provider_file_id' => $stored->id,
                'provider_store_id' => $store->id,
                'provider_document_id' => $added->id !== $stored->id ? $added->id : null,
                'metadata' => $metadata,
            ]);
        });
    }

    private function resolveStore(User $user): Store
    {
        if ($user->ai_document_store_id !== null) {
            return Stores::get($user->ai_document_store_id);
        }

        $store = Stores::create('Documents for '.$user->email);

        $user->forceFill(['ai_document_store_id' => $store->id])->save();

        return $store;
    }
}
