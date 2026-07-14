<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        $names = [
            'اداره کل بهداشت و درمان',
            'بیمارستان شهید فهمیده',
            'مرکز بهداشت شماره یک',
            'مرکز بهداشت شماره دو',
            'اورژانس اجتماعی',
            'واحد مالی و اداری',
            'واحد آموزش',
            'واحد فنی و عمرانی',
            'پایگاه بهداشتی شهید چمران',
            'خانه بهداشت روستایی',
            'واحد IT و فناوری اطلاعات',
            'واحد منابع انسانی',
            'واحد بهداشت محیط',
            'واحد بهداشت حرفه‌ای',
            'واحد مبارزه با بیماری‌ها',
        ];

        return [
            'name' => fake()->randomElement($names).' '.fake()->numberBetween(1, 99),
            'description' => fake()->optional(0.6)->sentence(8),
            'region_id' => null,
            'parent_id' => null,
            'unit_type_id' => null,
            'boundary_id' => null,
            'lat' => fake()->latitude(29.5, 39.5),
            'lng' => fake()->longitude(44.0, 63.5),
        ];
    }
}
