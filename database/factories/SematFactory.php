<?php

namespace Database\Factories;

use App\Models\Semat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Semat>
 */
class SematFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'رئیس',
                'معاون',
                'مدیر',
                'کارشناس',
                'مسئول',
                'کارشناس ارشد',
                'کاردان',
                'کارمند',
                'نگهبان',
                'راننده',
                'خدمتگزار',
                'کمک کارشناس',
                'مشاور',
                'دبیر',
                'سرپرست',
            ]),
        ];
    }
}
