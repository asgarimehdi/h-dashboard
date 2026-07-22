<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HealthHouseBoundarySeeder extends Seeder
{
    /**
     * Seed health house (type 9) boundaries using hardcoded WKT data.
     * To regenerate via PostGIS, run: php artisan generate:health-house-boundaries
     */
    public function run(): void
    {
        $boundaries = $this->getBoundaries();

        foreach ($boundaries as $unitId => $wkt) {
            $boundaryId = DB::table('boundaries')->insertGetId([
                'boundary' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Unit::where('id', $unitId)->update(['boundary_id' => $boundaryId]);
        }
    }

    private function getBoundaries(): array
    {
        return require __DIR__ . '/health_house_boundaries_data.php';
    }
}
