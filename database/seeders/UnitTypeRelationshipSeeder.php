<?php

namespace Database\Seeders;

use App\Models\UnitTypeRelationship;
use Illuminate\Database\Seeder;

class UnitTypeRelationshipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // در هر جفت، مقدار سمت چپ میتواند پدر سمت راست را داشته باشه
        $relationships = [
            [2, 1],
            [3, 2],
            [4, 3],
            [5, 4],
            [6, 4],
            [7, 4],
            [8, 5],
            [8, 6],
            [9, 6],
            [9, 7],
            [10, 5],
            [10, 6],
            [11, 8],
            [11, 10],
            [12, 9],
            [13, 4],
            [14, 4],
            [15, 4],
            [16, 4],
            [17, 4],
            [18, 4],
            [19, 4],
            [20, 4], // خانه های کارگری child of شبکه بهداشت
            [21, 4], // HSE child of شبکه بهداشت
            [18, 20], // خانه بهداشت کارگری child of خانه های کارگری
            [18, 21], // خانه بهداشت کارگری child of HSE
            [22, 4], // واحد ستادی child of شبکه بهداشت (ستاد زیرمجموعه شبکه است)
            [22, 22], // واحد ستادی child of واحد ستادی (زیرمجموعه‌های داخل ستاد)
            [17, 17], // فوریت child of فوریت (پایگاه‌های فوریت زیر واحد فوریت)
            [23, 4], // مرکز روان child of شبکه بهداشت (مرکز سراج زیر شبکه)
        ];

        foreach ($relationships as [$childId, $parentId]) {
            UnitTypeRelationship::create([
                'child_unit_type_id' => $childId,
                'allowed_parent_unit_type_id' => $parentId,
            ]);
        }
    }
}
