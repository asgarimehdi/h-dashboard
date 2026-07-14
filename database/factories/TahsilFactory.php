<?php

namespace Database\Factories;

use App\Models\Tahsil;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tahsil>
 */
class TahsilFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'زیر دیپلم',
                'دیپلم',
                'فوق دیپلم',
                'کارشناسی',
                'کارشناسی ارشد',
                'دکتری',
                'فوق دکتری',
            ]),
        ];
    }
}
