<?php

namespace Database\Factories;

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Patient;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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
            'recorded_at' => static function (array $attributes): string {
                $diagnosed = Carbon::parse($attributes['diagnosed_at']);
                $recorded = $diagnosed->copy()->addDays(fake()->numberBetween(0, 30));

                return ($recorded->isAfter(now()) ? now() : $recorded)->format('Y-m-d');
            },
            'fields' => [],
        ];
    }
}
