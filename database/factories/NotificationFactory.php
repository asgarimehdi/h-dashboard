<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['ticket_created', 'ticket_forwarded', 'ticket_completed', 'todo_assigned']),
            'title' => fake()->sentence(4),
            'body' => fake()->optional(0.8)->sentence(8),
            'icon' => fake()->randomElement(['o-bell', 'o-check', 'o-ticket', 'o-information-circle']),
            'color' => fake()->randomElement(['text-info', 'text-success', 'text-warning', 'text-error']),
            'url' => fake()->optional(0.7)->url(),
            'data' => fake()->optional(0.6)->words(3),
            'is_read' => fake()->boolean(30),
            'read_at' => fake()->optional(0.3)->dateTimeThisMonth(),
        ];
    }

    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => true,
            'read_at' => now(),
        ]);
    }
}
