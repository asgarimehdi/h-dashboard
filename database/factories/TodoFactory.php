<?php

namespace Database\Factories;

use App\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
{
    public function definition(): array
    {
        $startAt = fake()->dateTimeBetween('-1 month', '+2 weeks');
        $endAt = fake()->optional(0.7)->dateTimeBetween($startAt, '+1 month');

        return [
            'title' => fake('fa_IR')->sentence(5),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_completed' => false,
            'unit_id' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['is_completed' => true]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['is_completed' => false]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_at' => now()->subDays(10),
            'end_at' => now()->subDays(2),
        ]);
    }
}
