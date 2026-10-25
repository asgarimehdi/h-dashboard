<?php

namespace App\Services\Disease;

use App\Support\BaseUnitTypes;
use App\Support\QuantileBuckets;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * GeoJSON builder for the disease map (issue #1245, spec §6).
 *
 * Two shapes share one entry point:
 *   - `zoom < ZOOM_SWITCH` → region choropleth (county, per-province fallback)
 *   - `zoom >= ZOOM_SWITCH` → unit markers
 *
 * The service is pure: scope ids and dates are arguments, never `auth()` or
 * `session()`, so it runs in a queue worker too (#836). Caching lives in the
 * controller. Features never carry PII — only counts, rates and the
 * organizational name.
 */
final class DiseaseMapService
{
    /**
     * The single source of the region/unit boundary in the map. It is returned
     * in `meta.zoom_switch` so the client never hard-codes 9.
     */
    public const ZOOM_SWITCH = 9;

    public function __construct(private readonly DiseaseAggregationService $aggregation) {}

    /**
     * @param  array<int>  $accessibleIds
     * @param  array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null  $bbox
     * @return array{features: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function map(
        int $diseaseId,
        array $accessibleIds,
        Carbon $from,
        Carbon $to,
        int $zoom,
        ?array $bbox
    ): array {
        if ($zoom >= self::ZOOM_SWITCH) {
            return $this->unitMap($diseaseId, $accessibleIds, $from, $to, $bbox);
        }

        return $this->regionMap($diseaseId, $accessibleIds, $from, $to, $bbox);
    }

    /**
     * The accessible base units that can carry a marker: active, a base type,
     * and with coordinates. Ordered by id for a deterministic layer.
     *
     * @param  array<int>  $accessibleIds
     * @return list<int>
     */
    public function baseUnitIds(array $accessibleIds): array
    {
        return $this->baseUnitQuery($accessibleIds, null)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int>  $accessibleIds
     * @param  array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null  $bbox
     */
    private function baseUnitQuery(array $accessibleIds, ?array $bbox): Builder
    {
        $query = DB::table('units')
            ->whereIn('id', $accessibleIds)
            ->whereIn('unit_type_id', BaseUnitTypes::IDS)
            ->where('is_active', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        if ($bbox !== null) {
            $query->whereBetween('lat', [$bbox['minLat'], $bbox['maxLat']])
                ->whereBetween('lng', [$bbox['minLon'], $bbox['maxLon']]);
        }

        return $query->orderBy('id');
    }

    /**
     * Unit markers above the zoom switch.
     *
     * Two queries: the base units and the per-unit counts (over the WHOLE
     * scope, so cases on non-base units are reported as unmapped rather than
     * silently dropped).
     *
     * @param  array<int>  $accessibleIds
     * @param  array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null  $bbox
     * @return array{features: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function unitMap(
        int $diseaseId,
        array $accessibleIds,
        Carbon $from,
        Carbon $to,
        ?array $bbox
    ): array {
        $units = $this->baseUnitQuery($accessibleIds, $bbox)
            ->get(['id', 'name', 'lat', 'lng']);

        $counts = $this->aggregation->unitCaseCounts($diseaseId, $accessibleIds, $from, $to);

        $features = [];
        $placedCases = 0;

        foreach ($units as $unit) {
            $cases = $counts[(int) $unit->id] ?? 0;
            $placedCases += $cases;

            $features[] = [
                'type' => 'Feature',
                'id' => 'unit:'.$unit->id,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $unit->lng, (float) $unit->lat],
                ],
                'properties' => [
                    'level' => 'unit',
                    'unit_id' => (int) $unit->id,
                    'name' => $unit->name,
                    'cases' => $cases,
                ],
            ];
        }

        return [
            'features' => $features,
            'meta' => [
                'level' => 'unit',
                'zoom_switch' => self::ZOOM_SWITCH,
                'classes' => 0,
                'breaks' => [],
                'fallback_provinces' => 0,
                'unmapped_cases' => max(0, array_sum($counts) - $placedCases),
            ],
        ];
    }

    /**
     * County polygons with a per-province, all-or-nothing fallback:
     * a province whose counties are incomplete renders as ONE province polygon
     * (never a centroid, never a half-empty county layer).
     *
     * @param  array<int>  $accessibleIds
     * @param  array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null  $bbox
     * @return array{features: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function regionMap(
        int $diseaseId,
        array $accessibleIds,
        Carbon $from,
        Carbon $to,
        ?array $bbox
    ): array {
        $counts = $this->aggregation->regionCaseCounts($diseaseId, $accessibleIds, $from, $to);
        $populations = $this->aggregation->regionPopulations($accessibleIds);

        $provinces = [];
        $countiesByProvince = [];
        $boundaryIds = [];

        $regions = DB::table('regions')
            ->whereIn('type', ['province', 'county'])
            ->orderBy('id')
            ->get(['id', 'name', 'parent_id', 'type', 'boundary_id']);

        foreach ($regions as $region) {
            if ($region->boundary_id !== null) {
                $boundaryIds[(int) $region->boundary_id] = true;
            }

            if ($region->type === 'province') {
                $provinces[] = $region;
            } else {
                $countiesByProvince[(int) $region->parent_id][] = $region;
            }
        }

        $geometries = $this->geometries(array_keys($boundaryIds), $bbox);

        /** @var list<array{region: object, level: string, cases: int, population: int|null, rate: float|null}> $plans */
        $plans = [];
        $fallbackProvinces = 0;
        $placedCases = 0;

        foreach ($provinces as $province) {
            $counties = $countiesByProvince[(int) $province->id] ?? [];
            $allCountiesMapped = $counties !== [] && $this->everyBoundaryMapped($counties);

            if ($allCountiesMapped) {
                foreach ($counties as $county) {
                    $cases = $counts['byRegion'][(int) $county->id] ?? 0;
                    $placedCases += $cases;

                    $plans[] = [
                        'region' => $county,
                        'level' => 'county',
                        'cases' => $cases,
                        'population' => $populations[(int) $county->id] ?? null,
                        'rate' => null,
                    ];
                }

                continue;
            }

            // No polygon to draw the province on: drop it entirely (escape
            // hatch #3 forbids inventing a centroid).
            if ($province->boundary_id === null) {
                continue;
            }

            $cases = $counts['byRegion'][(int) $province->id] ?? 0;
            $population = $populations[(int) $province->id] ?? null;
            $hasPopulation = $population !== null;

            foreach ($counties as $county) {
                $cases += $counts['byRegion'][(int) $county->id] ?? 0;
                $countyPopulation = $populations[(int) $county->id] ?? null;

                if ($countyPopulation !== null) {
                    $population = ($population ?? 0) + $countyPopulation;
                    $hasPopulation = true;
                }
            }

            $placedCases += $cases;

            if ($counties !== []) {
                $fallbackProvinces++;
            }

            $plans[] = [
                'region' => $province,
                'level' => 'province',
                'cases' => $cases,
                'population' => $hasPopulation ? $population : null,
                'rate' => null,
            ];
        }

        foreach ($plans as $index => $plan) {
            $plans[$index]['rate'] = $this->aggregation->rate($plan['cases'], $plan['population']);
        }

        $breaks = QuantileBuckets::breaks(array_map(
            fn (array $plan): ?float => $plan['rate'],
            $plans
        ));

        $features = [];
        $levels = [];

        foreach ($plans as $plan) {
            $levels[$plan['level']] = true;

            $region = $plan['region'];
            $boundaryId = $region->boundary_id;

            if ($boundaryId === null) {
                continue;
            }

            $geometry = $geometries[(int) $boundaryId] ?? null;

            if ($geometry === null) {
                continue; // outside the requested bbox
            }

            $population = $plan['population'];
            $rate = $plan['rate'];

            $features[] = [
                'type' => 'Feature',
                'id' => 'region:'.$region->id,
                'geometry' => $geometry,
                'properties' => [
                    'level' => $plan['level'],
                    'region_id' => (int) $region->id,
                    'name' => $region->name,
                    'cases' => $plan['cases'],
                    'population' => $population,
                    'rate' => $rate,
                    'class' => QuantileBuckets::classOf($rate, $breaks),
                ],
            ];
        }

        $totalCases = array_sum($counts['byRegion']) + $counts['unmapped'];

        return [
            'features' => $features,
            'meta' => [
                'level' => $this->levelOf(array_keys($levels)),
                'zoom_switch' => self::ZOOM_SWITCH,
                'classes' => $breaks === [] ? 0 : count($breaks) + 1,
                'breaks' => $breaks,
                'fallback_provinces' => $fallbackProvinces,
                'unmapped_cases' => max(0, $totalCases - $placedCases),
            ],
        ];
    }

    /**
     * @param  array<int, object>  $counties
     */
    private function everyBoundaryMapped(array $counties): bool
    {
        foreach ($counties as $county) {
            if ($county->boundary_id === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $levels
     */
    private function levelOf(array $levels): string
    {
        if ($levels === []) {
            return 'none';
        }

        if (count($levels) > 1) {
            return 'mixed';
        }

        return $levels[0];
    }

    /**
     * Batch-load GeoJSON for the boundaries, optionally clipped by the viewport.
     * Never the `Boundary::geojson` accessor in a loop — that is N+1.
     *
     * @param  array<int, int>  $boundaryIds
     * @param  array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null  $bbox
     * @return array<int, array{type: string, coordinates: mixed}>
     */
    private function geometries(array $boundaryIds, ?array $bbox): array
    {
        if ($boundaryIds === []) {
            return [];
        }

        $query = DB::table('boundaries')
            ->whereIn('id', $boundaryIds)
            ->select('id')
            ->selectRaw('ST_AsGeoJSON(boundary) AS geojson');

        if ($bbox !== null) {
            $query->whereRaw('ST_Intersects(boundary, ST_MakeEnvelope(?, ?, ?, ?, 4326))', [
                $bbox['minLon'],
                $bbox['minLat'],
                $bbox['maxLon'],
                $bbox['maxLat'],
            ]);
        }

        $geometries = [];

        foreach ($query->get() as $row) {
            $decoded = json_decode((string) $row->geojson, true);

            if (! is_array($decoded) || ! isset($decoded['type'], $decoded['coordinates'])) {
                continue;
            }

            if (! is_string($decoded['type']) || ! is_array($decoded['coordinates'])) {
                continue;
            }

            // One island → Polygon; several → keep every part.
            if ($decoded['type'] === 'MultiPolygon' && count($decoded['coordinates']) === 1) {
                $decoded = [
                    'type' => 'Polygon',
                    'coordinates' => $decoded['coordinates'][0],
                ];
            }

            $geometries[(int) $row->id] = [
                'type' => $decoded['type'],
                'coordinates' => $decoded['coordinates'],
            ];
        }

        return $geometries;
    }
}
