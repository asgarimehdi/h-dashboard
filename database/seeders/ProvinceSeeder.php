<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Province;

class ProvinceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $provinces = [
            ['name' => 'Tehran'],
            ['name' => 'Isfahan'],
            ['name' => 'Zanjan'],
            // می‌توانید استان‌های بیشتری اضافه کنید
        ];

        foreach ($provinces as $province) {
            Province::create($province);
        }
    }
}