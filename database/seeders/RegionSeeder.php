<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Region;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        // ایجاد استان‌ها
        $provinces = [
            ['name' => 'زنجان', 'type' => 'province'],
        ];

        foreach ($provinces as $province) {
            $region = Region::create($province);

            // ایجاد شهرستان‌ها برای هر استان
            $counties = [
                ['name' => "ابهر {$region->name}", 'type' => 'county', 'parent_id' => $region->id],
            ];

            foreach ($counties as $county) {
                Region::create($county);
            }
        }
    }
}