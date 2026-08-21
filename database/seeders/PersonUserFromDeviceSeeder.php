<?php

namespace Database\Seeders;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class PersonUserFromDeviceSeeder extends Seeder
{
    private array $unitCache = [];

    private array $sematCache = [];

    private array $radifCache = [];

    /**
     * Seed persons + users from the pre-processed device data.
     *
     * The CSV was parsed once and the result (resolved unit paths, semat, radif
     * and role per person) is hardcoded in data/person_users_from_devices.php,
     * so this seeder no longer touches the raw CSV at runtime.
     */
    public function run(): void
    {
        $records = require __DIR__.'/data/person_users_from_devices.php';

        foreach (DB::table('semats')->get() as $row) {
            $this->sematCache[$row->name] = $row->id;
        }
        foreach (DB::table('radifs')->get() as $row) {
            $this->radifCache[$row->name] = $row->id;
        }

        $roles = Role::all()->keyBy('name');
        $existingCodes = array_flip(DB::table('persons')->pluck('n_code')->all());

        // Bcrypt is ~200ms per hash — compute once, reuse for every user.
        $password = Hash::make('12345678');

        DB::transaction(function () use ($records, $roles, $existingCodes, $password) {
            foreach ($records as $record) {
                if (isset($existingCodes[$record['n_code']])) {
                    continue;
                }

                $unitId = $this->resolveUnitPath($record['unit']);
                $sematId = $this->findOrCreateSemat($record['semat']);
                $radifId = $this->findOrCreateRadif($record['radif']);

                Person::create([
                    'n_code' => $record['n_code'],
                    'f_name' => $record['f_name'],
                    'l_name' => $record['l_name'] ?? '',
                    't_id' => 1,
                    'e_id' => 1,
                    's_id' => $sematId,
                    'r_id' => $radifId,
                    'u_id' => $unitId,
                ]);

                $user = User::create([
                    'n_code' => $record['n_code'],
                    'password' => $password,
                ]);

                $user->units()->attach($unitId, [
                    'role' => 'staff',
                    'is_primary' => true,
                ]);

                $role = $roles[$record['role']] ?? null;
                if ($role) {
                    $user->assignRole($role);
                }
            }
        });
    }

    /**
     * Walk a hardcoded unit path (root → leaf) and return the leaf unit id,
     * matching each segment scoped to its parent and creating it if missing.
     */
    private function resolveUnitPath(array $path): int
    {
        $parentId = null;

        foreach ($path as $name) {
            $key = ($parentId ?? 'root').'|'.$name;

            if (isset($this->unitCache[$key])) {
                $parentId = $this->unitCache[$key];

                continue;
            }

            $query = DB::table('units')->where('name', $name);
            $parentId === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);

            $unitId = $query->value('id');

            if (! $unitId) {
                $unitId = Unit::create(['name' => $name, 'parent_id' => $parentId, 'is_active' => true])->id;
            }

            $this->unitCache[$key] = $unitId;
            $parentId = $unitId;
        }

        return $parentId;
    }

    private function findOrCreateSemat(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 1;
        }
        if (isset($this->sematCache[$name])) {
            return $this->sematCache[$name];
        }
        $id = DB::table('semats')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->sematCache[$name] = $id;

        return $id;
    }

    private function findOrCreateRadif(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 1;
        }
        if (isset($this->radifCache[$name])) {
            return $this->radifCache[$name];
        }
        $id = DB::table('radifs')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->radifCache[$name] = $id;

        return $id;
    }
}
