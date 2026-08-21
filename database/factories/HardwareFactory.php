<?php

namespace Database\Factories;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hardware>
 */
class HardwareFactory extends Factory
{
    protected $model = Hardware::class;

    public function definition(): array
    {
        $unit = Unit::factory()->create();
        $person = Person::factory()->create(['u_id' => $unit->id]);

        return [
            'n_code' => $person->n_code,
            'pc_name' => fake()->bothify('PC-####'),
            'type' => fake()->randomElement(['desktop', 'laptop', 'server']),
            'os' => fake()->randomElement(['Windows 10', 'Windows 11', 'Ubuntu 22.04']),
            'shutdown' => false,
            'mark' => false,
        ];
    }
}
