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
            'وزارت بهداشت',// 1
            'دانشگاه علوم پزشکی', // 2
            'معاونت بهداشت', // 3
//            'معاونت درمان',
//            'معاونت آموزش',
//            'معاونت توسعه',
//            'مرکز بهداشت استان',
            'شبکه بهداشت', // 4
//            'مرکز بهداشت شهرستان',
            'مرکز خدمات جامع سلامت شهری', //5
            'مرکز خدمات جامع سلامت شهری روستایی', //6
            'مرکز خدمات جامع سلامت روستایی', //7
            'پایگاه سلامت ضمیمه',// 8
            'خانه بهداشت', //9
            'پایگاه سلامت غیر ضمیمه', //10
            'بلوک', // 11
            'قمر', //12
            'مرکز هاری', //13
            'تجمیع دندانپزشکی', //14
            'آزمایشگاه', //15
            'آموزشگاه', //16
            'فوریت', //17
            'خانه بهداشت کارگری', //18
            'بیمارستان', //19
        ];

        foreach ($unitTypes as $type) {
            UnitType::create([
                'name' => $type,
            ]);
        }
    }
}
