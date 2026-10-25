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

        $query = DiseaseCase::query()
            ->where('disease_id', $diseaseId)
            ->where(function ($query) use ($subtree): void {
                $query->whereIn('subject_unit_id', $subtree);
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

        $latestPerUnit = DB::table('unit_populations')
            ->selectRaw('DISTINCT ON (unit_id) unit_id, population')
            ->whereIn('unit_id', $subtree)
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

        $rate = ($population === null || $population === 0)
            ? null
            : round($cases / $population * 10000, 1);

        return [
            'cases' => $cases,
            'population' => $population,
            'rate' => $rate,
        ];
    }

    private function assertRange(?DateTimeInterface $from, ?DateTimeInterface $to): void
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('The aggregation start date must not be after the end date.');
        }
    }
}
