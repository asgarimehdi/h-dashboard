<?php

namespace Database\Factories;

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Patient;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiseaseCase>
 */
class DiseaseCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'disease_id' => Disease::factory(),
            'unit_id' => Unit::factory(),
            'subject_unit_id' => fn (array $attributes) => $attributes['unit_id'],
            'diagnosed_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'recorded_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'fields' => [],
        ];
    }
}
