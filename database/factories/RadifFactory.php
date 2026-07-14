<?php

namespace Database\Factories;

use App\Models\Radif;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Radif>
 */
class RadifFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numberBetween(1, 20),
        ];
    }
}
