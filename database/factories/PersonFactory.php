<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'n_code' => fake()->unique()->numerify('##########'),
            'f_name' => fake('fa_IR')->firstNameMale(),
            'l_name' => fake('fa_IR')->lastName(),
            't_id' => null,
            'e_id' => null,
            'r_id' => null,
            's_id' => null,
            'u_id' => null,
        ];
    }
}
