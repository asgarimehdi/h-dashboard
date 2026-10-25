<?php

namespace Database\Factories;

use App\Models\Disease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Disease>
 */
class DiseaseFactory extends Factory
{
    public function definition(): array
    {
        /** @var array<int, array{name_fa: string, slug: string}> $catalogue */
        $catalogue = [
            ['name_fa' => 'فشار خون', 'slug' => 'htn'],
            ['name_fa' => 'تالاسمی', 'slug' => 'thalassemia'],
            ['name_fa' => 'دیابت', 'slug' => 'diabetes'],
            ['name_fa' => 'اچ‌آی‌وی', 'slug' => 'hiv'],
            ['name_fa' => 'سرطان', 'slug' => 'cancer'],
        ];

        $base = $catalogue[fake()->numberBetween(0, count($catalogue) - 1)];

        // The suffix keeps several rows in one test from colliding on the
        // unique slug.
        $suffix = fake()->unique()->numberBetween(1, 999);

        return [
            'name_fa' => $base['name_fa'].' '.$suffix,
            'slug' => $base['slug'].'-'.$suffix,
            'is_active' => true,
            'sort' => fake()->numberBetween(1, 50),
        ];
    }
}
