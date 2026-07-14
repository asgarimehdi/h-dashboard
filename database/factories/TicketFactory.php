<?php

namespace Database\Factories;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_code' => fake()->unique()->numerify('TKT-#####'),
            'user_id' => null,
            'unit_id' => null,
            'subject' => fake('fa_IR')->sentence(4),
            'content' => fake('fa_IR')->paragraph(3),
            'priority' => fake()->randomElement(['low', 'normal', 'urgent']),
            'status' => fake()->randomElement(['created', 'forwarded', 'accepted', 'completed', 'rejected']),
            'task_id' => null,
            'current_assignee_id' => null,
            'accepted_at' => null,
            'completed_at' => null,
        ];
    }

    public function created(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'created']);
    }

    public function forwarded(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'forwarded']);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'accepted_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'rejected']);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => ['priority' => 'urgent']);
    }

    public function normal(): static
    {
        return $this->state(fn (array $attributes) => ['priority' => 'normal']);
    }

    public function low(): static
    {
        return $this->state(fn (array $attributes) => ['priority' => 'low']);
    }
}
