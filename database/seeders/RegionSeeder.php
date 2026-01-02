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
            ['name' => 'زنجان', 'type' => 'province', 'boundary_id' => 9],
        ];

        foreach ($provinces as $province) {
            $region = Region::create($province);
        }
            // ایجاد شهرستان‌ها برای هر استان
            $counties = [
                ['name' => "زنجان", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 1],
                ['name' => "ابهر", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 2],
                ['name' => "ایجرود", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 3],
                ['name' => "خدابنده", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 4],
                ['name' => "خرمدره", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 5],
                ['name' => "ماهنشان", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 6],
                ['name' => "سلطانیه", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 7],
                ['name' => "طارم", 'type' => 'county', 'parent_id' => 1, 'boundary_id' => 8],

            ];

            foreach ($counties as $county) {
                Region::create($county);
            }

    }
}
