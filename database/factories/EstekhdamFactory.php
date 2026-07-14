<?php

namespace Database\Factories;

use App\Models\Estekhdam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estekhdam>
 */
class EstekhdamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'رسمی',
                'پیمانی',
                'قراردادی',
                'طرح ساعتی',
                'خدماتی',
                'شرکتی',
                'نیروی قرارداد مشخص',
                'نیروی شرکت نفت',
                'بسیج',
                'داوطلب',
            ]),
        ];
    }
}
