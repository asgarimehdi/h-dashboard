<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        $unit = Unit::query()->value('id');

        if (! $unit) {
            $unit = Unit::create(['name' => 'Test Unit'])->id;
        }

        return [
            'n_code' => fake()->unique()->numerify('##########'),
            'f_name' => fake('fa_IR')->firstNameMale(),
            'l_name' => fake('fa_IR')->lastName(),
            't_id' => static::lookupId('tahsils'),
            'e_id' => static::lookupId('estekhdams'),
            'r_id' => static::lookupId('radifs'),
            's_id' => static::lookupId('semats'),
            'u_id' => $unit,
        ];
    }

    /**
     * Ensure a NOT NULL lookup reference exists by returning the id of an
     * existing row (or creating one) for the given reference table.
     */
    protected static function lookupId(string $table): int
    {
        $first = DB::table($table)->orderBy('id')->value('id');

        return $first ?: DB::table($table)->insertGetId(['name' => 'Test']);
    }
}
