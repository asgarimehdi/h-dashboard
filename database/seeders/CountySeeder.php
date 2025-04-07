<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\County;
use App\Models\Province;

class CountySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $provinces = Province::all();

        foreach ($provinces as $province) {
            $counties = [
                ['province_id' => $province->id, 'name' => "ابهر $province->name"],


            ];

            foreach ($counties as $county) {
                County::create($county);
            }
        }
    }
}
