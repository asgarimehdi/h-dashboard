<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('units')
            ->whereBetween('id', [1, 10])
            ->update([
                'can_receive_tickets' => true,
                'updated_at' => now(), 
            ]);
    }
}