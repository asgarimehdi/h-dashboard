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
        $data = [
            ['id' => 1, 'name' => 'کارشناس آی تی'],
            ['id' => 2, 'name' => 'آبدارچی'],
        ];

        foreach ($data as $item) {
            Semat::updateOrCreate(['id' => $item['id']], ['name' => $item['name']]);
        }

        // Reset sequence to max(id)+1 so subsequent auto-increment inserts don't conflict
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement("SELECT setval('semats_id_seq', (SELECT max(id) FROM semats), true)");
        }
    }
}
