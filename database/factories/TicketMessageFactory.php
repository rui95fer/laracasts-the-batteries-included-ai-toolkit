<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\TicketMessageType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => null,
            'type' => TicketMessageType::CustomerMessage,
            'body' => fake()->paragraph(),
            'author_name' => fake()->name(),
            'author_email' => fake()->safeEmail(),
        ];
    }

    public function internalNote(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TicketMessageType::InternalNote,
        ]);
    }

    public function agentReply(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TicketMessageType::AgentReply,
        ]);
    }
}
