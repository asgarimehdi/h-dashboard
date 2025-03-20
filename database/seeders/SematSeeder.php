<?php

namespace Database\Seeders;

use App\Models\Semat;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SematSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $names = [
            'کارشناس آی تی',
            'آبدارچی',
        ];

        foreach ($names as $name) {
            Semat::create([
                'name' => $name,
            ]);
        }
    }
}
