<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\County;
use App\Models\Province;
class CountySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // دریافت استان‌ها بر اساس نام
        $tehranProvince = Province::where('name', 'Tehran')->first();
        $isfahanProvince = Province::where('name', 'Isfahan')->first();
        $iszanjanProvince = Province::where('name', 'Zanjan')->first();

        $counties = [
            // شهرستان‌های مربوط به استان تهران
            ['province_id' => $tehranProvince->id, 'name' => 'Tehran County'],
            ['province_id' => $tehranProvince->id, 'name' => 'Rey County'],
            // شهرستان‌های مربوط به استان اصفهان
            ['province_id' => $isfahanProvince->id, 'name' => 'Isfahan County'],
            ['province_id' => $isfahanProvince->id, 'name' => 'Kashan County'],
            // شهرستان‌های مربوط به استان زنجان
            ['province_id' => $iszanjanProvince->id, 'name' => 'Zanjan County'],
            ['province_id' => $iszanjanProvince->id, 'name' => 'Abhar County'],
        ];

        foreach ($counties as $county) {
            County::create($county);
        }
    }
}