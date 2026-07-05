<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Person;
use App\Models\User;
use App\Models\Unit;
use Spatie\Permission\Models\Permission;
use Faker\Factory as FakerFactory;

class PersonUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = FakerFactory::create();
        // Get all unit IDs to assign randomly
        $unitIds = Unit::pluck('id')->toArray();
        // Get all permission names for random assignment
        $permissionNames = Permission::pluck('name')->toArray();

        // Get lookup IDs for personnel
        $estekhdamIds = \App\Models\Estekhdam::pluck('id')->toArray();
        $tahsilIds = \App\Models\Tahsil::pluck('id')->toArray();
        $sematIds = \App\Models\Semat::pluck('id')->toArray();
        $radifIds = \App\Models\Radif::pluck('id')->toArray();

        for ($i = 0; $i < 10; $i++) {
            // Generate a unique national code (10 digits)
            do {
                $nCode = $faker->unique()->numerify('##########');
            } while (Person::where('n_code', $nCode)->exists());

            // Randomly pick a unit for the person
            $unitId = $unitIds[array_rand($unitIds)];

            // Create Person record
            $person = Person::create([
                'n_code' => $nCode,
                'f_name' => $faker->firstName,
                'l_name' => $faker->lastName,
                'e_id'   => !empty($estekhdamIds) ? $estekhdamIds[array_rand($estekhdamIds)] : null,
                't_id'   => !empty($tahsilIds) ? $tahsilIds[array_rand($tahsilIds)] : null,
                's_id'   => !empty($sematIds) ? $sematIds[array_rand($sematIds)] : null,
                'r_id'   => !empty($radifIds) ? $radifIds[array_rand($radifIds)] : null,
                'u_id'   => $unitId,
            ]);

            // Create associated User record
            $user = User::create([
                'n_code'   => $nCode,
                'password' => Hash::make('password'), // default password
            ]);

            // Assign 1‑3 random permissions to the user (if any permissions exist)
            if (!empty($permissionNames)) {
                $numPermissions = rand(1, min(3, count($permissionNames)));
                $randomPermissions = array_rand(array_flip($permissionNames), $numPermissions);
                // array_rand returns a single value when $numPermissions == 1, normalize to array
                $randomPermissions = (array) $randomPermissions;
                $user->givePermissionTo($randomPermissions);
            }

            // Attach the user to the unit via pivot (staff role, primary)
            $user->units()->attach($unitId, [
                'role'       => 'staff',
                'is_primary' => true,
            ]);
        }
    }
}
