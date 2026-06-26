<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PersonsTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('persons')->insert([
            [
                'n_code' => '4400176134',
                'f_name' => 'صادق',
                'l_name' => 'بیگلر',
                't_id' => 1,
                'e_id' => 1,
                's_id' => 1,
                'r_id' => 1,
                'u_id' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'n_code' => '4411015056',
                'f_name' => 'مهدی',
                'l_name' => 'عسگری',
                't_id' => 1,
                'e_id' => 1,
                's_id' => 1,
                'r_id' => 1,
                'u_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'n_code' => '4400176143',
                'f_name' => 'شهاب',
                'l_name' => 'عباسی',
                't_id' => 1,
                'e_id' => 1,
                's_id' => 1,
                'r_id' => 1,
                'u_id' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
