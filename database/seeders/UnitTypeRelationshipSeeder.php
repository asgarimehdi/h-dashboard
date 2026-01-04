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
            [12, 4],
            [13, 4],
            [14, 4],
            [15, 4],
            [16, 4],
            [17, 4],
            [18, 4],
            [19, 4],
        ];

        foreach ($relationships as [$childId, $parentId]) {
            UnitTypeRelationship::create([
                'child_unit_type_id'         => $childId,
                'allowed_parent_unit_type_id' => $parentId,
            ]);
        }
    }
}
