<?php

namespace Database\Factories;

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'n_code' => fake()->unique()->numerify('##########'),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Ensure the backing Person exists BEFORE the User is inserted, because
     * users.n_code has an FK to persons.n_code (created at insert time).
     */
    public function configure(): static
    {
        return $this->afterMaking(function ($user) {
            if ($user->n_code && ! Person::where('n_code', $user->n_code)->exists()) {
                $unitId = Unit::query()->value('id') ?? Unit::create(['name' => 'Test Unit'])->id;

                Person::create([
                    'n_code' => $user->n_code,
                    'f_name' => fake()->firstName(),
                    'l_name' => fake()->lastName(),
                    't_id' => Tahsil::query()->value('id') ?? DB::table('tahsils')->insertGetId(['name' => 'Test']),
                    'e_id' => Estekhdam::query()->value('id') ?? DB::table('estekhdams')->insertGetId(['name' => 'Test']),
                    'r_id' => Radif::query()->value('id') ?? DB::table('radifs')->insertGetId(['name' => 'Test']),
                    's_id' => Semat::query()->value('id') ?? DB::table('semats')->insertGetId(['name' => 'Test']),
                    'u_id' => $unitId,
                ]);
            }
        });
    }
}
