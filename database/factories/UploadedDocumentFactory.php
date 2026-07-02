<?php

namespace Database\Factories;

use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UploadedDocument>
 */
class UploadedDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'file_name' => fake()->slug(3).'.pdf',
            'provider_file_id' => 'file_'.fake()->unique()->bothify('????????????????'),
            'provider_store_id' => 'store_'.fake()->unique()->bothify('????????????????'),
            'provider_document_id' => 'doc_'.fake()->unique()->bothify('????????????????'),
            'metadata' => [
                'user_id' => 0,
                'provider_file_id' => 'file_'.fake()->unique()->bothify('????????????????'),
            ],
        ];
    }

    public function forUser(User $user): static
    {
        $fileId = 'file_'.fake()->unique()->bothify('????????????????');

        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'provider_file_id' => $fileId,
            'metadata' => [
                'user_id' => $user->id,
                'provider_file_id' => $fileId,
            ],
        ]);
    }
}
