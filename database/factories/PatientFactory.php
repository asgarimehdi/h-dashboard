<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    public function definition(): array
    {
        $firstNames = [
            'علی', 'محمد', 'رضا', 'حسین', 'مهدی', 'فاطمه', 'زهرا', 'مریم',
            'سارا', 'نرگس', 'احمد', 'کاظم', 'سمیه', 'لیلا', 'امیر',
        ];

        $lastNames = [
            'محمدی', 'حسینی', 'رضایی', 'کریمی', 'موسوی', 'احمدی', 'جعفری',
            'صادقی', 'قاسمی', 'رحیمی', 'نوری', 'شریفی', 'کاظمی', 'هاشمی',
        ];

        $cities = [
            'تهران', 'مشهد', 'اصفهان', 'شیراز', 'تبریز', 'کرج', 'اهواز',
            'قم', 'کرمانشاه', 'رشت', 'زاهدان', 'همدان', 'یزد', 'اردبیل',
        ];

        return [
            'kod_melli' => str_pad((string) fake()->unique()->numberBetween(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'first_name' => fake()->randomElement($firstNames),
            'last_name' => fake()->randomElement($lastNames),
            'birth_date' => fake()->optional(0.9)->dateTimeBetween('-80 years', '-1 year'),
            'gender' => fake()->randomElement(['male', 'female']),
            'city' => fake()->optional(0.7)->randomElement($cities),
        ];
    }
}
