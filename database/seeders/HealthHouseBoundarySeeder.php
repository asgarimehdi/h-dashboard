<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HealthHouseBoundarySeeder extends Seeder
{
    public function run(): void
    {
        $counties = Region::where('type', 'county')
            ->whereNotNull('boundary_id')
            ->get();

        foreach ($counties as $county) {
            $units = Unit::where('region_id', $county->id)
                ->where('unit_type_id', 9)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->pluck('id', 'id')
                ->toArray();

            if (count($units) < 3) {
                continue;
            }

            $unitIds = array_keys($units);
            $points = Unit::whereIn('id', $unitIds)
                ->get()
                ->map(fn ($u) => "ST_SetSRID(ST_MakePoint({$u->lng}, {$u->lat}), 4326)")
                ->toArray();

            $sql = $this->buildVoronoiQuery($unitIds, $points, $county->boundary_id);
            $results = DB::select($sql);

            foreach ($results as $row) {
                $boundaryId = DB::table('boundaries')->insertGetId([
                    'boundary' => DB::raw("ST_GeomFromText('{$row->wkt}', 4326)"),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Unit::where('id', $row->unit_id)->update(['boundary_id' => $boundaryId]);
            }
        }
    }

    private function buildVoronoiQuery(array $unitIds, array $points, int $boundaryId): string
    {
        $ids = implode(',', $unitIds);
        $pts = implode(',', $points);

        return <<<SQL
            WITH points AS (
                SELECT
                    unnest(ARRAY[{$ids}]) AS unit_id,
                    unnest(ARRAY[{$pts}]) AS geom
            ),
            voronoi AS (
                SELECT (ST_Dump(
                    ST_VoronoiPolygons(ST_Collect(ARRAY[{$pts}]))
                )).geom AS polygon
            )
            SELECT
                p.unit_id,
                ST_AsText(
                    ST_Multi(
                        ST_Intersection(
                            ST_Transform(v.polygon, 4326),
                            (SELECT boundary FROM boundaries WHERE id = {$boundaryId})
                        )
                    )
                ) AS wkt
            FROM voronoi v
            JOIN points p ON ST_Contains(v.polygon, p.geom)
            WHERE ST_Intersects(
                ST_Transform(v.polygon, 4326),
                (SELECT boundary FROM boundaries WHERE id = {$boundaryId})
            )
        SQL;
    }
}
