<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Unit;
use App\Models\Boundary;

class AbharCenterBoundarySeeder extends Seeder
{
    public function run(): void
    {
        // Abhar county boundary_id = 2 (from BoundarySeeder)
        $abharBoundaryId = 2;

        // Get the Abhar county boundary geometry
        $countyGeom = DB::table('boundaries')
            ->where('id', $abharBoundaryId)
            ->value('boundary');

        if (! $countyGeom) {
            $this->command?->error('Abhar county boundary (id=2) not found. Run BoundarySeeder first.');
            return;
        }

        // Get all Abhar center units with GPS coordinates
        $centers = Unit::where('region_id', 3)
            ->whereIn('unit_type_id', [5, 6, 7])
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get();

        if ($centers->isEmpty()) {
            $this->command?->error('No Abhar center units found with GPS coordinates.');
            return;
        }

        $this->command?->info("Found {$centers->count()} Abhar center units.");

        // Build a geometry collection of points
        $pointInserts = [];
        foreach ($centers as $center) {
            $pointInserts[] = sprintf(
                "ST_SetSRID(ST_MakePoint(%s, %s), 4326)",
                $center->lng,
                $center->lat
            );
        }
        $pointsCollection = 'ST_Collect(ARRAY[' . implode(', ', $pointInserts) . '])';

        // Generate Voronoi polygons using PostGIS, clipped to Abhar county boundary
        // 1. Generate Voronoi from the point collection
        // 2. Dump individual polygons
        // 3. For each polygon, find which center point it contains
        // 4. Clip to county boundary using ST_Intersection
        $voronoiQuery = "
            WITH points AS (
                SELECT
                    unnest(ARRAY[" . implode(',', array_map(fn($c) => $c->id, $centers->toArray())) . "]) AS unit_id,
                    unnest(ARRAY[" . implode(',', $pointInserts) . "]) AS geom
            ),
            voronoi AS (
                SELECT (ST_Dump(
                    ST_VoronoiPolygons({$pointsCollection})
                )).geom AS polygon
            )
            SELECT
                p.unit_id,
                ST_AsText(
                    ST_Multi(
                        ST_Intersection(
                            ST_Transform(v.polygon, 4326),
                            (SELECT boundary FROM boundaries WHERE id = {$abharBoundaryId})
                        )
                    )
                ) AS wkt
            FROM voronoi v
            JOIN points p ON ST_Contains(v.polygon, p.geom)
            WHERE ST_Intersects(
                ST_Transform(v.polygon, 4326),
                (SELECT boundary FROM boundaries WHERE id = {$abharBoundaryId})
            )
        ";

        $results = DB::select($voronoiQuery);

        $created = 0;
        foreach ($results as $row) {
            $wkt = $row->wkt;
            $unitId = $row->unit_id;

            if (empty($wkt) || $wkt === 'GEOMETRYCOLLECTION EMPTY') {
                $this->command?->warn("Skipping unit {$unitId}: empty geometry after clipping.");
                continue;
            }

            // Create boundary record
            $boundaryId = DB::table('boundaries')->insertGetId([
                'boundary' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update unit to reference this boundary
            Unit::where('id', $unitId)->update(['boundary_id' => $boundaryId]);
            $created++;
        }

        $this->command?->info("Created {$created} boundaries for Abhar center units.");
    }
}
