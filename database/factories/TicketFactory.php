<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\User;
use App\TicketDepartment;
use App\TicketPriority;
use App\TicketSentiment;
use App\TicketStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
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
            'subject' => fake()->sentence(4),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
            'department' => TicketDepartment::Support,
            'sentiment' => TicketSentiment::Neutral,
            'last_message_at' => now(),
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Pending,
            'closed_at' => null,
        ]);
    }
}
