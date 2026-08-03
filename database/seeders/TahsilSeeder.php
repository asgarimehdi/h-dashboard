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
        $data = [
            ['id' => 1, 'name' => 'بیسواد'],
            ['id' => 2, 'name' => 'دیپلم'],
            ['id' => 3, 'name' => 'فوق دیپلم'],
            ['id' => 4, 'name' => 'لیسانس'],
            ['id' => 5, 'name' => 'فوق لیسانس'],
            ['id' => 6, 'name' => 'دکتری'],
        ];

        foreach ($data as $item) {
            Tahsil::updateOrCreate(['id' => $item['id']], ['name' => $item['name']]);
        }

        // Reset sequence to max(id)+1 so subsequent auto-increment inserts don't conflict
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement("SELECT setval('tahsils_id_seq', (SELECT max(id) FROM tahsils), true)");
        }
    }
}
