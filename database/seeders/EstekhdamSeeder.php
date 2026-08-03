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
        $data = [
            ['id' => 1, 'name' => 'رسمی'],
            ['id' => 2, 'name' => 'پیمانی'],
            ['id' => 3, 'name' => 'قراردادی تبصره 3'],
            ['id' => 4, 'name' => 'قراردادی تبصره 4'],
            ['id' => 5, 'name' => 'شرکتی'],
        ];

        foreach ($data as $item) {
            Estekhdam::updateOrCreate(['id' => $item['id']], ['name' => $item['name']]);
        }

        // Reset sequence to max(id)+1 so subsequent auto-increment inserts don't conflict
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement("SELECT setval('estekhdams_id_seq', (SELECT max(id) FROM estekhdams), true)");
        }
    }
}
