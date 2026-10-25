<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Patient;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use App\Services\Disease\DiseaseScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1241 Task 1 — the disease visibility predicate (V1–V5).
 *
 * A case is visible when EITHER its recording unit (`unit_id`) OR the
 * patient's unit (`subject_unit_id`) falls inside the viewer's subtree. The
 * two `whereIn`s must stay inside one `where(Closure)` group: an ungrouped
 * `orWhereIn` would let any later `where('disease_id', …)` be bypassed.
 *
 * The tree every test uses:
 *
 *     province
 *     └── staff
 *         ├── centerA
 *         │   └── houseX
 *         └── centerB
 *             └── houseY
 */
class DiseaseVisibilityScopeTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** @var array{province: Unit, staff: Unit, centerA: Unit, centerB: Unit, houseX: Unit, houseY: Unit} */
    private array $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $province = Unit::factory()->create();
        $staff = Unit::factory()->create(['parent_id' => $province->id]);
        $centerA = Unit::factory()->create(['parent_id' => $staff->id]);
        $centerB = Unit::factory()->create(['parent_id' => $staff->id]);
        $houseX = Unit::factory()->create(['parent_id' => $centerA->id]);
        $houseY = Unit::factory()->create(['parent_id' => $centerB->id]);

        $this->tree = compact('province', 'staff', 'centerA', 'centerB', 'houseX', 'houseY');
    }

    /** @return array<int> */
    private function subtree(Unit $unit): array
    {
        return Unit::descendantIds($unit->id)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function assertVisible(DiseaseCase $case, Unit $viewer, bool $expected): void
    {
        $seen = DiseaseCase::visibleTo($this->subtree($viewer))
            ->whereKey($case->id)
            ->exists();

        $expected
            ? $this->assertTrue($seen, "case {$case->id} should be visible to {$viewer->name}")
            : $this->assertFalse($seen, "case {$case->id} should NOT be visible to {$viewer->name}");
    }

    private function makeCase(Unit $recorder, Unit $subject): DiseaseCase
    {
        return DiseaseCase::factory()->create([
            'unit_id' => $recorder->id,
            'subject_unit_id' => $subject->id,
        ]);
    }

    /** @return array<int, DiseaseCase> */
    private function makeV1V2V3(): array
    {
        return [
            $this->makeCase($this->tree['houseX'], $this->tree['houseX']),
            $this->makeCase($this->tree['staff'], $this->tree['houseX']),
            $this->makeCase($this->tree['centerA'], $this->tree['houseX']),
        ];
    }

    public function test_v1_case_in_a_leaf_is_visible_up_its_own_branch(): void
    {
        $case = $this->makeCase($this->tree['houseX'], $this->tree['houseX']);

        $this->assertVisible($case, $this->tree['houseX'], true);
        $this->assertVisible($case, $this->tree['centerA'], true);
        $this->assertVisible($case, $this->tree['staff'], true);
        $this->assertVisible($case, $this->tree['province'], true);
        $this->assertVisible($case, $this->tree['centerB'], false);
        $this->assertVisible($case, $this->tree['houseY'], false);
    }

    public function test_v2_recorder_and_subject_in_different_branches(): void
    {
        $case = $this->makeCase($this->tree['staff'], $this->tree['houseX']);

        $this->assertVisible($case, $this->tree['staff'], true);
        $this->assertVisible($case, $this->tree['province'], true);
        $this->assertVisible($case, $this->tree['centerA'], true);
        $this->assertVisible($case, $this->tree['houseX'], true);
        $this->assertVisible($case, $this->tree['centerB'], false);
        $this->assertVisible($case, $this->tree['houseY'], false);
    }

    public function test_v3_recorder_above_subject(): void
    {
        $case = $this->makeCase($this->tree['centerA'], $this->tree['houseX']);

        $this->assertVisible($case, $this->tree['centerA'], true);
        $this->assertVisible($case, $this->tree['staff'], true);
        $this->assertVisible($case, $this->tree['province'], true);
        $this->assertVisible($case, $this->tree['houseX'], true);
        $this->assertVisible($case, $this->tree['centerB'], false);
        $this->assertVisible($case, $this->tree['houseY'], false);
    }

    public function test_v4_province_and_center_a_see_all_three_while_center_b_sees_none(): void
    {
        [$v1, $v2, $v3] = $this->makeV1V2V3();

        foreach ([$v1, $v2, $v3] as $case) {
            $this->assertVisible($case, $this->tree['province'], true);
            $this->assertVisible($case, $this->tree['centerA'], true);
            $this->assertVisible($case, $this->tree['centerB'], false);
        }
    }

    public function test_v5_house_y_sees_none_of_the_three(): void
    {
        foreach ($this->makeV1V2V3() as $case) {
            $this->assertVisible($case, $this->tree['houseY'], false);
        }
    }

    public function test_empty_scope_is_fail_closed(): void
    {
        $this->makeV1V2V3();

        $this->assertSame(0, DiseaseCase::visibleTo([])->count());
    }

    public function test_disease_filter_is_not_bypassed_by_the_visibility_or(): void
    {
        $d1 = Disease::factory()->create();
        $d2 = Disease::factory()->create();

        $caseD1 = DiseaseCase::factory()->create([
            'disease_id' => $d1->id,
            'unit_id' => $this->tree['houseX']->id,
            'subject_unit_id' => $this->tree['houseX']->id,
        ]);
        DiseaseCase::factory()->create([
            'disease_id' => $d2->id,
            'unit_id' => $this->tree['houseX']->id,
            'subject_unit_id' => $this->tree['houseX']->id,
        ]);

        $ids = DiseaseCase::visibleTo($this->subtree($this->tree['houseX']))
            ->where('disease_id', $d1->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([$caseD1->id], $ids);
    }

    public function test_patient_visibility_follows_case_visibility(): void
    {
        $withCase = Patient::factory()->create();
        $withoutCase = Patient::factory()->create();

        DiseaseCase::factory()->create([
            'patient_id' => $withCase->id,
            'unit_id' => $this->tree['houseX']->id,
            'subject_unit_id' => $this->tree['houseX']->id,
        ]);

        $visibleFromX = Patient::visibleTo($this->subtree($this->tree['houseX']))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertContains($withCase->id, $visibleFromX);
        $this->assertNotContains($withoutCase->id, $visibleFromX);

        $this->assertSame(0, Patient::visibleTo($this->subtree($this->tree['houseY']))->count());
    }

    public function test_subtree_ids_returns_the_accessible_intersection(): void
    {
        $scope = new DiseaseScope;

        $this->assertEqualsCanonicalizing(
            $this->subtree($this->tree['centerA']),
            $scope->subtreeIds($this->tree['centerA']->id, $this->subtree($this->tree['province']))
        );

        $this->assertSame(
            [],
            $scope->subtreeIds($this->tree['centerB']->id, $this->subtree($this->tree['centerA']))
        );

        $this->assertSame(
            [$this->tree['centerA']->id],
            $scope->subtreeIds($this->tree['centerA']->id, [$this->tree['centerA']->id])
        );
    }

    public function test_access_service_scope_is_the_same_set_that_makes_v2_visible(): void
    {
        $case = $this->makeCase($this->tree['staff'], $this->tree['houseX']);

        $user = User::factory()->create();
        Session::put('current_unit_id', $this->tree['staff']->id);
        $this->actingAs($user);

        $ids = app(AccessService::class)->accessibleUnitIds($user);

        $this->assertEqualsCanonicalizing($this->subtree($this->tree['staff']), $ids);
        $this->assertTrue(DiseaseCase::visibleTo($ids)->whereKey($case->id)->exists());
    }
}
