<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\UnitTypeRelationship;

class UnitTypeRelationshipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // آرایه مقادیر فیلد child_unit_type_id
        $childUnitTypeIds = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 14];

        // آرایه مقادیر فیلد allowed_parent_unit_type_id
        $allowedParentUnitTypeIds = [1, 2, 2, 2, 2, 3, 3, 8, 9, 9, 9, 10, 11, 12];

        // اطمینان از اینکه هر دو آرایه طول یکسانی دارند
        $count = count($childUnitTypeIds);

        for ($i = 0; $i < $count; $i++) {
            UnitTypeRelationship::create([
                'child_unit_type_id'         => $childUnitTypeIds[$i],
                'allowed_parent_unit_type_id' => $allowedParentUnitTypeIds[$i],
            ]);
        }
    }
}