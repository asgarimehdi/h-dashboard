<?php

namespace Database\Seeders;

use App\Models\Radif;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RadifSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $names = [
            'فناوری',
            'خدمات',
        ];

        foreach ($names as $name) {
            Radif::create([
                'name' => $name,
            ]);
        }
    }
}
