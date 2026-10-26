<?php

namespace App\Services\Disease;

use App\Models\DiseaseCase;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Count / population / rate for a unit's subtree (spec §5).
 *
 * Count is deliberately one-sided: only `subject_unit_id` is filtered inside
 * the subtree. Adding the bidirectional `visibleTo` predicate here would count
 * recorder-side cases whose patient lives elsewhere and make the aggregate
 * wrong. Population is the sum of each unit's latest year — no layer override,
 * no manual number, no cache.
 */
final class DiseaseAggregationService
{
    public function __construct(private readonly DiseaseScope $scope) {}

    /**
     * @param  array<int>  $accessibleUnitIds
     */
    public function cases(
        int $diseaseId,
        int $unitId,
        array $accessibleUnitIds,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): int {
        $this->assertRange($from, $to);

        $subtree = $this->scope->subtreeIds($unitId, $accessibleUnitIds);

        if ($subtree === []) {
            return 0;
        }

        return $this->countIn($diseaseId, $subtree, $from, $to);
    }

    /**
     * Cases for a SET of unit ids — the scope of a multi-unit actor, not one
     * subtree (decision 12). The predicate is unconditional `whereIn`, so an
     * empty set compiles to `0 = 1`; the early return only spares the query.
     *
     * @param  array<int>  $unitIds
     */
    public function countIn(
        int $diseaseId,
        array $unitIds,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): int {
        $this->assertRange($from, $to);

        if ($unitIds === []) {
            return 0;
        }

        $query = DiseaseCase::query()
            ->where('disease_id', $diseaseId)
            ->where(function ($query) use ($unitIds): void {
                $query->whereIn('subject_unit_id', $unitIds);
            });

        if ($from !== null) {
            $query->where('diagnosed_at', '>=', $from->format('Y-m-d'));
        }

        if ($to !== null) {
            $query->where('diagnosed_at', '<=', $to->format('Y-m-d'));
        }

        return (int) $query->count();
    }

    /**
     * Sum of each unit's latest recorded year in the subtree, or null when
     * no unit has a population row at all.
     *
     * @param  array<int>  $accessibleUnitIds
     */
    public function population(int $unitId, array $accessibleUnitIds): ?int
    {
        $subtree = $this->scope->subtreeIds($unitId, $accessibleUnitIds);

        if ($subtree === []) {
            return null;
        }

        return $this->populationOf($subtree);
    }

    /**
     * Sum of each unit's latest recorded year over a SET of units.
     *
     * Population is never read from an upper-layer row (decision 10): the
     * subtree sum is the only source of truth.
     *
     * @param  array<int>  $unitIds
     */
    public function populationOf(array $unitIds): ?int
    {
        if ($unitIds === []) {
            return null;
        }

        $latestPerUnit = DB::table('unit_populations')
            ->selectRaw('DISTINCT ON (unit_id) unit_id, population')
            ->whereIn('unit_id', $unitIds)
            ->orderBy('unit_id')
            ->orderByDesc('year');

        $row = DB::query()
            ->fromSub($latestPerUnit, 'latest')
            ->selectRaw('COUNT(*) AS units, COALESCE(SUM(population), 0) AS total')
            ->first();

        if ($row === null || (int) $row->units === 0) {
            return null;
        }

        return (int) $row->total;
    }

    /**
     * Rate per 10,000 with one decimal; null when the population is missing
     * or zero (decision 17).
     */
    public function rate(int $cases, ?int $population): ?float
    {
        return ($population === null || $population === 0)
            ? null
            : round($cases / $population * 10000, 1);
    }

    /**
     * @param  array<int>  $accessibleUnitIds
     * @return array{cases: int, population: int|null, rate: float|null}
     */
    public function stats(
        int $diseaseId,
        int $unitId,
        array $accessibleUnitIds,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $cases = $this->cases($diseaseId, $unitId, $accessibleUnitIds, $from, $to);
        $population = $this->population($unitId, $accessibleUnitIds);

        return [
            'cases' => $cases,
            'population' => $population,
            'rate' => $this->rate($cases, $population),
        ];
    }

    /**
     * Stats across the actor's whole scope (a SET of units, possibly several
     * roots). `[]` means "in scope of nothing" — zero/null without a query.
     *
     * @param  array<int>  $accessibleUnitIds
     * @return array{cases: int, population: int|null, rate: float|null}
     */
    public function scopeStats(
        int $diseaseId,
        array $accessibleUnitIds,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $unitIds = array_map(intval(...), $accessibleUnitIds);

        if ($unitIds === []) {
            return ['cases' => 0, 'population' => null, 'rate' => null];
        }

        $cases = $this->countIn($diseaseId, $unitIds, $from, $to);
        $population = $this->populationOf($unitIds);

        return [
            'cases' => $cases,
            'population' => $population,
            'rate' => $this->rate($cases, $population),
        ];
    }

    /**
     * Cases attributed to each region for the choropleth, in one query.
     *
     * `unmapped` holds the cases whose subject unit has no region — reported in
     * `meta`, never guessed into a neighbouring polygon (escape hatch #3).
     *
     * @param  array<int>  $accessibleUnitIds
     * @return array{byRegion: array<int, int>, unmapped: int}
     */
    public function regionCaseCounts(
        int $diseaseId,
        array $accessibleUnitIds,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $this->assertRange($from, $to);

        if ($accessibleUnitIds === []) {
            return ['byRegion' => [], 'unmapped' => 0];
        }

        $query = DB::table('disease_cases')
            ->join('units', 'units.id', '=', 'disease_cases.subject_unit_id')
            ->where('disease_cases.disease_id', $diseaseId)
            ->whereIn('disease_cases.subject_unit_id', $accessibleUnitIds)
            ->selectRaw('units.region_id AS region_id, COUNT(*) AS cases')
            ->groupBy('units.region_id');

        if ($from !== null) {
            $query->where('disease_cases.diagnosed_at', '>=', $from->format('Y-m-d'));
        }

        if ($to !== null) {
            $query->where('disease_cases.diagnosed_at', '<=', $to->format('Y-m-d'));
        }

        $byRegion = [];
        $unmapped = 0;

        foreach ($query->get() as $row) {
            $cases = (int) $row->cases;

            if ($row->region_id === null) {
                $unmapped += $cases;
            } else {
                $byRegion[(int) $row->region_id] = $cases;
            }
        }

        return ['byRegion' => $byRegion, 'unmapped' => $unmapped];
    }

    /**
     * Population per region: each unit's latest year, summed. One query.
     *
     * A unit whose region is null contributes to no region; a unit with no
     * population row contributes nothing (never read from a parent row).
     *
     * @param  array<int>  $accessibleUnitIds
     * @return array<int, int>
     */
    public function regionPopulations(array $accessibleUnitIds): array
    {
        if ($accessibleUnitIds === []) {
            return [];
        }

        $latestPerUnit = DB::table('unit_populations')
            ->selectRaw('DISTINCT ON (unit_id) unit_id, population')
            ->whereIn('unit_id', $accessibleUnitIds)
            ->orderBy('unit_id')
            ->orderByDesc('year');

        $rows = DB::query()
            ->fromSub($latestPerUnit, 'up')
            ->join('units', 'units.id', '=', 'up.unit_id')
            ->whereIn('up.unit_id', $accessibleUnitIds)
            ->whereNotNull('units.region_id')
            ->selectRaw('units.region_id AS region_id, SUM(up.population) AS population')
            ->groupBy('units.region_id')
            ->get();

        $populations = [];

        foreach ($rows as $row) {
            $populations[(int) $row->region_id] = (int) $row->population;
        }

        return $populations;
    }

    /**
     * Cases per subject unit, in one query. Units without cases are absent —
     * the marker layer fills 0 so the layer never looks empty.
     *
     * @param  array<int>  $unitIds
     * @return array<int, int>
     */
    public function unitCaseCounts(
        int $diseaseId,
        array $unitIds,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $this->assertRange($from, $to);

        if ($unitIds === []) {
            return [];
        }

        $query = DiseaseCase::query()
            ->where('disease_id', $diseaseId)
            ->whereIn('subject_unit_id', $unitIds)
            ->selectRaw('subject_unit_id, COUNT(*) AS cases')
            ->groupBy('subject_unit_id');

        if ($from !== null) {
            $query->where('diagnosed_at', '>=', $from->format('Y-m-d'));
        }

        if ($to !== null) {
            $query->where('diagnosed_at', '<=', $to->format('Y-m-d'));
        }

        $counts = [];

        foreach ($query->get() as $row) {
            $counts[(int) $row->subject_unit_id] = (int) $row->cases;
        }

        return $counts;
    }

    private function assertRange(?DateTimeInterface $from, ?DateTimeInterface $to): void
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('The aggregation start date must not be after the end date.');
        }
    }
}
