<?php

namespace Database\Factories;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $extensions = ['pdf', 'jpg', 'png', 'docx', 'xlsx', 'zip'];
        $ext = fake()->randomElement($extensions);
        $baseName = fake()->slug(2);

        return [
            'user_id' => null,
            'file_path' => "attachments/{$baseName}.{$ext}",
            'file_name' => "{$baseName}.{$ext}",
            'file_size' => fake()->numberBetween(1024, 10 * 1024 * 1024), // 1KB to 10MB
            'ticket_id' => null,
            'activity_id' => null,
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => 'attachments/'.fake()->slug(2).'.pdf',
            'file_name' => fake()->slug(2).'.pdf',
            'file_size' => fake()->numberBetween(50_000, 5_000_000),
        ]);
    }

    public function image(): static
    {
        $ext = fake()->randomElement(['jpg', 'png']);
        $name = fake()->slug(2);

        return $this->state(fn (array $attributes) => [
            'file_path' => "attachments/{$name}.{$ext}",
            'file_name' => "{$name}.{$ext}",
            'file_size' => fake()->numberBetween(20_000, 3_000_000),
        ]);
    }
}
