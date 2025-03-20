<?php

namespace Database\Seeders;

use App\Models\Tahsil;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TahsilSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $names = [
            'بیسواد',
            'دیپلم',
            'فوق دیپلم',
            'لیسانس',
            'فوق لیسانس',
            'دکتری',
        ];

        foreach ($names as $name) {
            Tahsil::create([
                'name' => $name,
            ]);
        }
    }
}
