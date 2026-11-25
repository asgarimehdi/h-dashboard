<?php

namespace Database\Seeders;

use App\Models\User;
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
            // #1803: never write `is_primary => true` here — a user may
            // already have a primary on another unit, and the partial unique
            // index `user_units_one_primary_per_user` rejects a second one.
            // Attach/refresh the row unprimed, then let the single sanctioned
            // write path promote it.
            DB::table('user_units')->updateOrInsert(
                ['user_id' => $user->user_id, 'unit_id' => $user->unit_id],
                ['role' => 'staff', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]
            );

            User::find($user->user_id)?->setPrimaryUnit((int) $user->unit_id);
        }
    }
}
