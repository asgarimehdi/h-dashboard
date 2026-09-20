<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        // Ensure person record exists for the 4th user BEFORE user insert (FK constraint)
        DB::table('persons')->updateOrInsert(
            ['n_code' => '1000000001'],
            [
                'f_name' => 'کاربر',
                'l_name' => 'عادی',
                's_id' => 1,
                't_id' => 1,
                'e_id' => 1,
                'r_id' => 1,
                'u_id' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        DB::table('users')->insert([
            [
                'n_code' => '4411015056',
                'password' => Hash::make('12345678'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'n_code' => '4400176134',
                'password' => Hash::make('12345678'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'n_code' => '4400176143',
                'password' => Hash::make('12345678'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'n_code' => '1000000001',
                'password' => Hash::make('12345678'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}
