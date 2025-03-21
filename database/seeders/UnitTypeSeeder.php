<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\UnitType;

class UnitTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $unitTypes = [
            'وزارت خانه',
            'دانشگاه علوم پزشکی',
            'معاونت بهداشت',
            'معاونت درمان',
            'معاونت آموزش',
            'معاونت توسعه',
            'مرکز بهداشت استان',
            'شبکه بهداشت',
            'مرکز بهداشت شهرستان',
            'مرکز خدمات جامع سلامت شهری',
            'مرکز خدمات جامع سلامت شهری روستایی',
            'مرکز خدمات جامع سلامت روستایی',
            'پایگاه سلامت',
            'خانه بهداشت',
        ];

        foreach ($unitTypes as $type) {
            UnitType::create([
                'name' => $type,
            ]);
        }
    }
}