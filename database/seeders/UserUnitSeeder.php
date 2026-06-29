<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserUnitSeeder extends Seeder
{
    public function run(): void
    {
        $users = DB::table('users')
            ->join('persons', 'users.n_code', '=', 'persons.n_code')
            ->whereNotNull('persons.u_id')
            ->select('users.id as user_id', 'persons.u_id as unit_id')
            ->get();

        foreach ($users as $user) {
            DB::table('user_units')->updateOrInsert(
                ['user_id' => $user->user_id, 'unit_id' => $user->unit_id],
                ['role' => 'staff', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
