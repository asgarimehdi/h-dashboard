<?php

namespace Database\Factories;

use App\Models\UnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitType>
 */
class UnitTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'سازمان مرکزی',
                'اداره کل',
                'معاونت',
                'اداره',
                'شعبه',
                'بیمارستان',
                'مرکز بهداشت',
                'پایگاه بهداشت',
                'خانه بهداشت',
                'مرکز مشاوره',
                'واحد ستادی',
                'واحد اجرایی',
                'نمایندگی',
                'دفتر',
            ]),
            'description' => fake()->optional(0.5)->sentence(6),
        ];
    }
}
