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
        $data = [
            ['id' => 1, 'name' => 'فناوری'],
            ['id' => 2, 'name' => 'خدمات'],
        ];

        foreach ($data as $item) {
            Radif::updateOrCreate(['id' => $item['id']], ['name' => $item['name']]);
        }

        // Reset sequence to max(id)+1 so subsequent auto-increment inserts don't conflict
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement("SELECT setval('radifs_id_seq', (SELECT max(id) FROM radifs), true)");
        }
    }
}
