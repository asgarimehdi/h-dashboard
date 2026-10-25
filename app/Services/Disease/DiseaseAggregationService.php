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

    private function assertRange(?DateTimeInterface $from, ?DateTimeInterface $to): void
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('The aggregation start date must not be after the end date.');
        }
    }
}
