<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Unit;
class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Unit::create([
            'name'          => 'وزارت بهداشت درمان و آموزش پزشکی',
            'unit_type_id'  => 1,
            // سایر فیلدها می‌توانند به صورت دلخواه تنظیم شوند
            'description'   => null,
            'province_id'   => null,
            'county_id'     => null,
            'parent_id'     => null,
        ]);
    }
}