<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiseaseCase;
use App\Models\Patient;
use App\Models\Unit;
use App\Models\User;
use App\Services\Disease\PatientService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1241 Task 4 — the minimal patient domain service.
 *
 * Direct patient access is permission-gated in the UI/API layer, so this
 * service deliberately takes no scope. Deletes are refused while cases exist
 * (gate decision "ب"), and every write records only the actor's unit — never
 * the patient's name or national code.
 */
class PatientServiceTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Unit $unit;

    private User $user;

    private PatientService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = Unit::factory()->create();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->service = app(PatientService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'kod_melli' => '1234567890',
            'first_name' => 'علی',
            'last_name' => 'محمدی',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'city' => 'تهران',
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

    public function test_create_stores_the_patient_and_audits_only_the_actor_unit(): void
    {
        $patient = $this->service->create($this->payload(), $this->unit->id);

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'kod_melli' => '1234567890',
        ]);

        $log = $this->latestLog('created');
        $this->assertSame(Patient::class, $log->subject_type);
        $this->assertSame($patient->id, $log->subject_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame(['unit_id' => $this->unit->id], $log->new_values);
    }

    public function test_create_rejects_a_duplicate_national_code(): void
    {
        $this->service->create($this->payload(), $this->unit->id);

        $this->assertValidationError(
            fn () => $this->service->create($this->payload(['first_name' => 'رضا']), $this->unit->id),
            'kod_melli'
        );
    }

    public function test_create_rejects_a_gender_outside_the_two_allowed_values(): void
    {
        $this->assertValidationError(
            fn () => $this->service->create($this->payload(['gender' => 'other']), $this->unit->id),
            'gender'
        );
    }

    public function test_update_saves_changes_and_audits(): void
    {
        $patient = $this->service->create($this->payload(), $this->unit->id);

        $updated = $this->service->update($patient->id, $this->payload(['city' => 'مشهد']), $this->unit->id);

        $this->assertSame('مشهد', $updated->city);

        $log = $this->latestLog('updated');
        $this->assertSame($patient->id, $log->subject_id);
        $this->assertSame(['unit_id' => $this->unit->id], $log->new_values);
    }

    public function test_delete_removes_the_patient_and_audits(): void
    {
        $patient = $this->service->create($this->payload(), $this->unit->id);

        $this->service->delete($patient->id, $this->unit->id);

        $this->assertDatabaseMissing('patients', ['id' => $patient->id]);
        $this->assertSame($patient->id, $this->latestLog('deleted')->subject_id);
    }

    public function test_delete_is_refused_while_a_case_exists(): void
    {
        $patient = $this->service->create($this->payload(), $this->unit->id);
        DiseaseCase::factory()->create([
            'patient_id' => $patient->id,
            'unit_id' => $this->unit->id,
            'subject_unit_id' => $this->unit->id,
        ]);

        $this->assertValidationError(
            fn () => $this->service->delete($patient->id, $this->unit->id),
            'patient'
        );

        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
    }
}
