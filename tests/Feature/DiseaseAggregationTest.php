<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Unit;
use App\Services\Disease\DiseaseAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1241 Task 5 — subtree case count, population sum and rate (spec §5).
 *
 * Count filters on `subject_unit_id` inside the requested subtree and on the
 * ANALYTICAL date (`diagnosed_at`), never `recorded_at`. Population is the sum
 * of each unit's latest year. Rate is per 10,000 with one decimal. An unknown
 * or out-of-scope unit is fail-closed (0 / null / null), never "all units".
 */
class DiseaseAggregationTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Unit $province;

    private Unit $staff;

    private Unit $centerA;

    private Unit $centerB;

    private Unit $houseX;

    private Unit $houseY;

    private Disease $disease;

    private DiseaseAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->province = Unit::factory()->create();
        $this->staff = Unit::factory()->create(['parent_id' => $this->province->id]);
        $this->centerA = Unit::factory()->create(['parent_id' => $this->staff->id]);
        $this->centerB = Unit::factory()->create(['parent_id' => $this->staff->id]);
        $this->houseX = Unit::factory()->create(['parent_id' => $this->centerA->id]);
        $this->houseY = Unit::factory()->create(['parent_id' => $this->centerB->id]);

        $this->disease = Disease::factory()->create();
        $this->service = app(DiseaseAggregationService::class);
    }

    /** @return array<int> */
    private function subtree(Unit $unit): array
    {
        return Unit::descendantIds($unit->id)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function caseAt(string $diagnosedAt, Unit $unit, ?Disease $disease = null): DiseaseCase
    {
        return DiseaseCase::factory()->create([
            'disease_id' => ($disease ?? $this->disease)->id,
            'unit_id' => $unit->id,
            'subject_unit_id' => $unit->id,
            'diagnosed_at' => $diagnosedAt,
        ]);
    }

    private function populationRow(Unit $unit, int $year, int $population): void
    {
        DB::table('unit_populations')->insert([
            'unit_id' => $unit->id,
            'year' => $year,
            'population' => $population,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function marchFrom(): Carbon
    {
        return Carbon::parse('2026-03-01');
    }

    private function marchTo(): Carbon
    {
        return Carbon::parse('2026-03-31');
    }

    public function test_subtree_count_includes_descendants_only(): void
    {
        $this->caseAt('2026-03-01', $this->houseX);
        $this->caseAt('2026-03-02', $this->houseY);

        $ids = $this->subtree($this->staff);

        $this->assertSame(2, $this->service->cases($this->disease->id, $this->staff->id, $ids));
        $this->assertSame(1, $this->service->cases($this->disease->id, $this->centerA->id, $this->subtree($this->centerA)));
        $this->assertSame(1, $this->service->cases($this->disease->id, $this->houseX->id, $this->subtree($this->houseX)));
        $this->assertSame(1, $this->service->cases($this->disease->id, $this->centerB->id, $this->subtree($this->centerB)));
    }

    public function test_count_filters_by_disease(): void
    {
        $other = Disease::factory()->create();
        $this->caseAt('2026-03-01', $this->houseX, $this->disease);
        $this->caseAt('2026-03-01', $this->houseX, $other);

        $this->assertSame(
            1,
            $this->service->cases($this->disease->id, $this->houseX->id, $this->subtree($this->houseX))
        );
    }

    public function test_date_range_is_inclusive(): void
    {
        $this->caseAt('2026-02-28', $this->houseX);
        $this->caseAt('2026-03-01', $this->houseX);
        $this->caseAt('2026-03-31', $this->houseX);
        $this->caseAt('2026-04-01', $this->houseX);

        $this->assertSame(
            2,
            $this->service->cases(
                $this->disease->id,
                $this->houseX->id,
                $this->subtree($this->houseX),
                $this->marchFrom(),
                $this->marchTo()
            )
        );
    }

    public function test_count_uses_the_analytical_date_not_recorded_at(): void
    {
        DiseaseCase::factory()->create([
            'disease_id' => $this->disease->id,
            'unit_id' => $this->houseX->id,
            'subject_unit_id' => $this->houseX->id,
            'diagnosed_at' => '2026-03-11',
            'recorded_at' => '2026-04-15',
        ]);

        $this->assertSame(
            1,
            $this->service->cases(
                $this->disease->id,
                $this->houseX->id,
                $this->subtree($this->houseX),
                $this->marchFrom(),
                $this->marchTo()
            )
        );
    }

    public function test_open_ended_ranges_work(): void
    {
        $this->caseAt('2026-02-28', $this->houseX);
        $this->caseAt('2026-03-01', $this->houseX);
        $this->caseAt('2026-03-31', $this->houseX);
        $this->caseAt('2026-04-01', $this->houseX);

        $ids = $this->subtree($this->houseX);

        $this->assertSame(3, $this->service->cases($this->disease->id, $this->houseX->id, $ids, $this->marchFrom()));
        $this->assertSame(3, $this->service->cases($this->disease->id, $this->houseX->id, $ids, null, $this->marchTo()));
    }

    public function test_from_after_to_is_a_programmer_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->cases(
            $this->disease->id,
            $this->houseX->id,
            $this->subtree($this->houseX),
            $this->marchTo(),
            $this->marchFrom()
        );
    }

    public function test_population_sums_the_latest_year_of_each_unit(): void
    {
        $this->populationRow($this->houseX, 2024, 1000);
        $this->populationRow($this->houseX, 2025, 1200);
        $this->populationRow($this->houseY, 2025, 800);

        $this->assertSame(2000, $this->service->population($this->staff->id, $this->subtree($this->staff)));
        $this->assertSame(1200, $this->service->population($this->centerA->id, $this->subtree($this->centerA)));
        $this->assertSame(1200, $this->service->population($this->houseX->id, $this->subtree($this->houseX)));
    }

    public function test_missing_population_is_null(): void
    {
        $this->assertNull($this->service->population($this->houseX->id, $this->subtree($this->houseX)));
    }

    public function test_zero_population_stays_zero_and_rate_is_null(): void
    {
        $this->populationRow($this->houseX, 2026, 0);
        $this->caseAt('2026-03-01', $this->houseX);

        $stats = $this->service->stats($this->disease->id, $this->houseX->id, $this->subtree($this->houseX));

        $this->assertSame(0, $stats['population']);
        $this->assertNull($stats['rate']);
    }

    public function test_rate_is_per_ten_thousand_with_one_decimal(): void
    {
        DiseaseCase::factory()->count(34)->create([
            'disease_id' => $this->disease->id,
            'unit_id' => $this->houseX->id,
            'subject_unit_id' => $this->houseX->id,
        ]);
        $this->populationRow($this->houseX, 2026, 42000);

        $stats = $this->service->stats($this->disease->id, $this->houseX->id, $this->subtree($this->houseX));

        $this->assertSame(34, $stats['cases']);
        $this->assertSame(42000, $stats['population']);
        $this->assertSame(8.1, $stats['rate']);
    }

    public function test_rate_edges(): void
    {
        $this->caseAt('2026-03-01', $this->houseX);

        $noPopulation = $this->service->stats($this->disease->id, $this->houseX->id, $this->subtree($this->houseX));
        $this->assertNull($noPopulation['rate']);

        $this->populationRow($this->houseX, 2026, 5000);

        $diseaseWithoutCases = Disease::factory()->create();
        $zeroCount = $this->service->stats($diseaseWithoutCases->id, $this->houseX->id, $this->subtree($this->houseX));
        $this->assertSame(0, $zeroCount['cases']);
        $this->assertSame(0.0, $zeroCount['rate']);
    }

    public function test_stats_shape_is_scalar_and_has_no_pii(): void
    {
        $this->populationRow($this->houseX, 2026, 5000);
        $this->caseAt('2026-03-01', $this->houseX);

        $stats = $this->service->stats($this->disease->id, $this->houseX->id, $this->subtree($this->houseX));

        $this->assertSame(['cases', 'population', 'rate'], array_keys($stats));

        foreach ($stats as $value) {
            $this->assertTrue($value === null || is_scalar($value));
        }
    }

    public function test_out_of_scope_unit_is_fail_closed(): void
    {
        $this->caseAt('2026-03-01', $this->houseY);
        $this->populationRow($this->houseY, 2026, 5000);

        $stats = $this->service->stats($this->disease->id, $this->houseY->id, $this->subtree($this->centerA));

        $this->assertSame(0, $stats['cases']);
        $this->assertNull($stats['population']);
        $this->assertNull($stats['rate']);
    }

    public function test_stats_runs_two_queries_after_the_subtree_is_warm(): void
    {
        $this->populationRow($this->houseX, 2026, 5000);
        $this->caseAt('2026-03-01', $this->houseX);

        $ids = $this->subtree($this->houseX);

        $this->assertQueryCount(2, fn () => $this->service->stats(
            $this->disease->id,
            $this->houseX->id,
            $ids,
            $this->marchFrom(),
            $this->marchTo()
        ));
    }

    // ---------------------------------------------------------------------
    // #1245 — scope-wide stats (a SET of roots, not one root)
    // ---------------------------------------------------------------------

    public function test_scope_stats_sums_multiple_roots(): void
    {
        $otherProvince = Unit::factory()->create();
        $otherCenter = Unit::factory()->create(['parent_id' => $otherProvince->id]);

        $this->caseAt('2026-03-01', $this->houseX);
        $this->caseAt('2026-03-02', $this->houseY);
        $this->caseAt('2026-03-03', $otherCenter);

        $ids = array_values(array_unique(array_merge(
            $this->subtree($this->staff),
            $this->subtree($otherProvince)
        )));

        $stats = $this->service->scopeStats($this->disease->id, $ids, $this->marchFrom(), $this->marchTo());

        // One root would miss the other province's case — the exact bug
        // decision 12 exists to prevent.
        $this->assertSame(2, $this->service->cases(
            $this->disease->id,
            $this->staff->id,
            $ids,
            $this->marchFrom(),
            $this->marchTo()
        ));
        $this->assertSame(3, $stats['cases']);
    }

    public function test_scope_stats_filters_by_disease_and_analytical_date(): void
    {
        $other = Disease::factory()->create();

        $this->caseAt('2026-02-28', $this->houseX);
        $this->caseAt('2026-03-01', $this->houseX);
        $this->caseAt('2026-03-31', $this->houseY);
        $this->caseAt('2026-04-01', $this->houseY);
        $this->caseAt('2026-03-15', $this->houseX, $other);

        // `recorded_at` is outside the range on purpose: only `diagnosed_at`
        // is analytical.
        DiseaseCase::factory()->create([
            'disease_id' => $this->disease->id,
            'unit_id' => $this->houseX->id,
            'subject_unit_id' => $this->houseX->id,
            'diagnosed_at' => '2026-03-15',
            'recorded_at' => '2026-09-01',
        ]);

        $stats = $this->service->scopeStats(
            $this->disease->id,
            $this->subtree($this->staff),
            $this->marchFrom(),
            $this->marchTo()
        );

        $this->assertSame(3, $stats['cases']);
    }

    public function test_scope_stats_rate_is_per_ten_thousand_with_one_decimal(): void
    {
        DiseaseCase::factory()->count(34)->create([
            'disease_id' => $this->disease->id,
            'unit_id' => $this->houseX->id,
            'subject_unit_id' => $this->houseX->id,
        ]);
        $this->populationRow($this->houseX, 2026, 42000);

        $stats = $this->service->scopeStats($this->disease->id, $this->subtree($this->houseX));

        $this->assertSame(34, $stats['cases']);
        $this->assertSame(42000, $stats['population']);
        $this->assertSame(8.1, $stats['rate']);
    }

    public function test_scope_stats_missing_or_zero_population_has_null_rate(): void
    {
        $this->caseAt('2026-03-01', $this->houseX);

        $missing = $this->service->scopeStats($this->disease->id, $this->subtree($this->houseX));
        $this->assertNull($missing['population']);
        $this->assertNull($missing['rate']);

        $this->populationRow($this->houseX, 2026, 0);

        $zero = $this->service->scopeStats($this->disease->id, $this->subtree($this->houseX));
        $this->assertSame(0, $zero['population']);
        $this->assertNull($zero['rate']);
    }

    public function test_scope_stats_is_fail_closed_without_a_query(): void
    {
        $this->caseAt('2026-03-01', $this->houseX);

        $this->assertQueryCount(0, function (): void {
            $this->assertSame(
                ['cases' => 0, 'population' => null, 'rate' => null],
                $this->service->scopeStats($this->disease->id, [])
            );
        });
    }

    public function test_scope_stats_runs_two_queries(): void
    {
        $this->populationRow($this->houseX, 2026, 5000);
        $this->caseAt('2026-03-01', $this->houseX);

        $ids = $this->subtree($this->houseX);

        $this->assertQueryCount(2, fn () => $this->service->scopeStats(
            $this->disease->id,
            $ids,
            $this->marchFrom(),
            $this->marchTo()
        ));
    }

    public function test_scope_stats_shape_is_scalar_and_has_no_pii(): void
    {
        $this->populationRow($this->houseX, 2026, 5000);
        $this->caseAt('2026-03-01', $this->houseX);

        $stats = $this->service->scopeStats($this->disease->id, $this->subtree($this->houseX));

        $this->assertSame(['cases', 'population', 'rate'], array_keys($stats));

        foreach ($stats as $value) {
            $this->assertTrue($value === null || is_scalar($value));
        }
    }
}
