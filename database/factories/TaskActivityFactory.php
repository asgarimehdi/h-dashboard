<?php

namespace Database\Factories;

use App\Models\TaskActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskActivity>
 */
class TaskActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => null,
            'user_id' => null,
            'action' => fake()->randomElement(['created', 'forwarded', 'rejected', 'finished', 'accepted']),
            'description' => fake()->optional(0.7)->sentence(10),
            'is_internal' => fake()->boolean(20),
            'to_unit_id' => null,
            'to_user_id' => null,
        ];
    }

    public function created(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'created',
            'description' => 'تیکت جدید ثبت شد.',
        ]);
    }

    public function forwarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'forwarded',
            'description' => 'تیکت به واحد دیگری ارجاع شد.',
            'is_internal' => false,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'accepted',
            'description' => 'تیکت پذیرفته شد و در حال پیگیری است.',
            'is_internal' => false,
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'finished',
            'description' => 'تیکت با موفقیت به پایان رسید.',
            'is_internal' => false,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'rejected',
            'description' => 'تیکت رد شد.',
            'is_internal' => false,
        ]);
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes) => ['is_internal' => true]);
    }
}
