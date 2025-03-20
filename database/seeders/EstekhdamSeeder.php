<?php

namespace Database\Seeders;

use App\Models\Estekhdam;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EstekhdamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $names = [
            'رسمی',
            'پیمانی',
            'قراردادی تبصره 3',
            'قراردادی تبصره 4',
            'شرکتی',
        ];

        foreach ($names as $name) {
            Estekhdam::create([
                'name' => $name,
            ]);
        }
    }
}
