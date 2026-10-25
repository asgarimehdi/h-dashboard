<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\DiseaseFieldDef;
use App\Models\Patient;
use App\Models\Unit;
use App\Models\User;
use App\Services\Disease\DiseaseCaseService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1241 Task 3 — the case write service.
 *
 * The recorder unit is always a server argument (never read from the payload),
 * the patient's unit must be inside the caller's scope, a brand-new case can
 * only use an active disease, the duplicate unique index is translated to a
 * domain error, and every write is audited with non-clinical metadata only.
 */
class DiseaseCaseServiceTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Unit $province;

    private Unit $center;

    private Unit $house;

    private Unit $other;

    /** @var array<int> */
    private array $accessIds;

    private Patient $patient;

    private Disease $disease;

    private User $user;

    private DiseaseCaseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->province = Unit::factory()->create();
        $this->center = Unit::factory()->create(['parent_id' => $this->province->id]);
        $this->house = Unit::factory()->create(['parent_id' => $this->center->id]);
        $this->other = Unit::factory()->create();

        $this->accessIds = $this->subtree($this->center);
        $this->patient = Patient::factory()->create();
        $this->disease = Disease::factory()->create();
        $this->user = User::factory()->create();

        DiseaseFieldDef::create([
            'disease_id' => $this->disease->id,
            'field_key' => 'sys',
            'label_fa' => 'قند خون',
            'type' => 'number',
            'required' => true,
            'sort' => 1,
        ]);

        $this->actingAs($this->user);
        $this->service = app(DiseaseCaseService::class);
    }

    /** @return array<int> */
    private function subtree(Unit $unit): array
    {
        return Unit::descendantIds($unit->id)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'patient_id' => $this->patient->id,
            'disease_id' => $this->disease->id,
            'subject_unit_id' => $this->house->id,
            'diagnosed_at' => '2026-03-11',
            'fields' => ['sys' => '140'],
        ], $overrides);
    }

    private function assertValidationError(Closure $callback, string $key): void
    {
        try {
            $callback();
            $this->fail("Expected ValidationException for {$key}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), "Missing error key {$key}");
        }
    }

    private function latestLog(string $type): ActivityLog
    {
        return ActivityLog::query()->where('type', $type)->latest('id')->firstOrFail();
    }

    public function test_create_uses_the_server_recorder_unit_and_ignores_payload_unit_id(): void
    {
        $case = $this->service->create(
            $this->payload(['unit_id' => $this->other->id]),
            $this->center->id,
            $this->accessIds
        );

        $this->assertSame($this->center->id, $case->unit_id);
        $this->assertSame($this->house->id, $case->subject_unit_id);
    }

    public function test_create_defaults_recorded_at_to_today(): void
    {
        $this->travelTo(Carbon::parse('2026-05-20 10:00:00'));

        $case = $this->service->create($this->payload(), $this->center->id, $this->accessIds);

        $this->assertSame('2026-05-20', $case->recorded_at->format('Y-m-d'));
        $this->assertSame('2026-03-11', $case->diagnosed_at->format('Y-m-d'));
    }

    public function test_create_rejects_a_subject_unit_outside_the_scope(): void
    {
        $this->assertValidationError(
            fn () => $this->service->create(
                $this->payload(['subject_unit_id' => $this->other->id]),
                $this->center->id,
                $this->accessIds
            ),
            'subject_unit_id'
        );
    }

    public function test_create_rejects_an_inactive_disease(): void
    {
        $inactive = Disease::factory()->create(['is_active' => false]);

        $this->assertValidationError(
            fn () => $this->service->create(
                $this->payload(['disease_id' => $inactive->id]),
                $this->center->id,
                $this->accessIds
            ),
            'disease_id'
        );
    }

    public function test_create_rejects_invalid_fields(): void
    {
        $this->assertValidationError(
            fn () => $this->service->create(
                $this->payload(['fields' => ['sys' => 'abc']]),
                $this->center->id,
                $this->accessIds
            ),
            'fields.sys'
        );
    }

    public function test_create_writes_a_created_audit_row_without_fields_or_pii(): void
    {
        $case = $this->service->create($this->payload(), $this->center->id, $this->accessIds);

        $log = $this->latestLog('created');

        $this->assertSame(DiseaseCase::class, $log->subject_type);
        $this->assertSame($case->id, $log->subject_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame($this->center->id, $log->new_values['unit_id']);
        $this->assertSame($this->house->id, $log->new_values['subject_unit_id']);
        $this->assertArrayNotHasKey('fields', $log->new_values);
        $this->assertArrayNotHasKey('first_name', $log->new_values);
        $this->assertArrayNotHasKey('kod_melli', $log->new_values);
    }

    public function test_create_translates_a_duplicate_into_a_validation_error(): void
    {
        $this->service->create($this->payload(), $this->center->id, $this->accessIds);

        $this->assertValidationError(
            fn () => $this->service->create($this->payload(), $this->center->id, $this->accessIds),
            'duplicate'
        );
    }

    public function test_update_outside_scope_is_not_found(): void
    {
        $case = DiseaseCase::factory()->create([
            'unit_id' => $this->other->id,
            'subject_unit_id' => $this->other->id,
        ]);

        $this->expectException(ModelNotFoundException::class);

        $this->service->update($case->id, $this->payload(), $this->accessIds);
    }

    public function test_update_replaces_fields_keeps_recorder_and_recorded_at(): void
    {
        $case = $this->service->create(
            $this->payload(['recorded_at' => '2026-04-01', 'fields' => ['sys' => '140']]),
            $this->center->id,
            $this->accessIds
        );

        $updated = $this->service->update(
            $case->id,
            $this->payload([
                'subject_unit_id' => $this->center->id,
                'fields' => ['sys' => '200'],
            ]),
            $this->accessIds
        );

        $this->assertSame(['sys' => 200], $updated->fields);
        $this->assertSame($this->center->id, $updated->unit_id);
        $this->assertSame('2026-04-01', $updated->recorded_at->format('Y-m-d'));

        $log = $this->latestLog('updated');
        $this->assertSame($this->house->id, $log->old_values['subject_unit_id']);
        $this->assertSame($this->center->id, $log->new_values['subject_unit_id']);
        $this->assertArrayNotHasKey('fields', $log->old_values);
        $this->assertArrayNotHasKey('fields', $log->new_values);
    }

    public function test_update_revalidates_the_submitted_subject_unit(): void
    {
        $case = $this->service->create($this->payload(), $this->center->id, $this->accessIds);

        $this->assertValidationError(
            fn () => $this->service->update(
                $case->id,
                $this->payload(['subject_unit_id' => $this->other->id]),
                $this->accessIds
            ),
            'subject_unit_id'
        );
    }

    public function test_update_allows_an_unchanged_inactive_disease_but_rejects_a_new_inactive_one(): void
    {
        $case = $this->service->create($this->payload(), $this->center->id, $this->accessIds);

        $this->disease->update(['is_active' => false]);

        $updated = $this->service->update(
            $case->id,
            $this->payload(['fields' => ['sys' => '150']]),
            $this->accessIds
        );
        $this->assertSame(['sys' => 150], $updated->fields);

        $inactive = Disease::factory()->create(['is_active' => false]);

        $this->assertValidationError(
            fn () => $this->service->update(
                $case->id,
                $this->payload(['disease_id' => $inactive->id]),
                $this->accessIds
            ),
            'disease_id'
        );
    }

    public function test_delete_in_scope_removes_and_audits(): void
    {
        $case = $this->service->create($this->payload(), $this->center->id, $this->accessIds);

        $this->service->delete($case->id, $this->accessIds);

        $this->assertDatabaseMissing('disease_cases', ['id' => $case->id]);
        $this->assertSame($case->id, $this->latestLog('deleted')->subject_id);
    }

    public function test_delete_outside_scope_is_not_found_and_keeps_the_row(): void
    {
        $case = DiseaseCase::factory()->create([
            'unit_id' => $this->other->id,
            'subject_unit_id' => $this->other->id,
        ]);

        try {
            $this->service->delete($case->id, $this->accessIds);
            $this->fail('Expected ModelNotFoundException');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('disease_cases', ['id' => $case->id]);
        }
    }

    public function test_create_reports_missing_patient_and_disease_as_domain_errors(): void
    {
        $this->assertValidationError(
            fn () => $this->service->create(
                $this->payload(['patient_id' => 999999]),
                $this->center->id,
                $this->accessIds
            ),
            'patient_id'
        );

        $this->assertValidationError(
            fn () => $this->service->create(
                $this->payload(['disease_id' => 999999]),
                $this->center->id,
                $this->accessIds
            ),
            'disease_id'
        );
    }

    public function test_create_rejects_a_non_array_fields_payload_and_an_invalid_date(): void
    {
        $this->assertValidationError(
            fn () => $this->service->create(
                $this->payload(['fields' => 'not-an-array']),
                $this->center->id,
                $this->accessIds
            ),
            'fields'
        );

        $this->assertValidationError(
            fn () => $this->service->create(
                $this->payload(['recorded_at' => '2026-02-30']),
                $this->center->id,
                $this->accessIds
            ),
            'recorded_at'
        );
    }
}
