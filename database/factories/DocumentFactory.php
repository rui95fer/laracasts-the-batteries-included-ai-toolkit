<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
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
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'embedding' => null,
        ];
    }

    /**
     * @param  array<int, float>  $embedding
     */
    public function withEmbedding(array $embedding): static
    {
        return $this->state(fn (): array => [
            'embedding' => $embedding,
        ]);
    }
}
