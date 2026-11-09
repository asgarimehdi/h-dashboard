<?php

use App\Models\ActivityLog;
use App\Models\Patient;
use App\Services\PatientService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpTestRequest;
use Illuminate\Validation\ValidationException;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1300 — the App patient service must apply its own rules on the normalized
 * input, write insert+audit atomically and translate DB duplicates into a
 * `duplicate` validation error. Post-fix expectations only: raw exceptions
 * before the fix are the bug this file pins shut.
 */
uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    ['user' => $this->actor, 'unit' => $this->actorUnit] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($this->actor);
});

/**
 * The service error keys for the given raw payload. A raw (non-validation)
 * exception propagates — that propagation is exactly what these tests pin.
 *
 * @param  array<string, mixed>  $overrides
 * @return list<string>
 */
function registryCreateErrors(array $overrides): array
{
    try {
        app(PatientService::class)->create(registryPayload($overrides));
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    test()->fail('create() raised no ValidationException at all');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registryPayload(array $overrides = []): array
{
    return array_merge([
        'kod_melli' => '0499370899',
        'first_name' => 'آرش',
        'last_name' => 'حسن‌زاده',
        'birth_date_jalali' => '1370/01/01',
        'gender' => 'male',
    ], $overrides);
}

test('an invalid national code is a validation error, not a 500', function () {
    expect(registryCreateErrors(['kod_melli' => '0012345678']))->toContain('kod_melli');

    $this->assertDatabaseCount('patients', 0);
});

test('an invalid gender is a validation error, not a 500', function () {
    expect(registryCreateErrors(['gender' => 'other']))->toContain('gender');

    $this->assertDatabaseCount('patients', 0);
});

test('a future birth date is a validation error, not a 500', function () {
    $future = Jalalian::fromCarbon(now()->addDay())->format('Y/m/d');

    expect(registryCreateErrors(['birth_date_jalali' => $future]))->toContain('birth_date_jalali');

    $this->assertDatabaseCount('patients', 0);
});

test('a malformed jalali birth date is a validation error, not a raw exception', function () {
    expect(registryCreateErrors(['birth_date_jalali' => 'garbage']))
        ->toContain('birth_date_jalali');

    $this->assertDatabaseCount('patients', 0);
});

test('whitespace-only names are validation errors, not a null-column violation', function () {
    expect(registryCreateErrors(['first_name' => '   ', 'last_name' => '']))
        ->toContain('first_name', 'last_name');

    $this->assertDatabaseCount('patients', 0);
});

test('persian digits are canonicalized before validation', function () {
    $patient = app(PatientService::class)->create(registryPayload([
        'kod_melli' => '۰۴۹۹۳۷۰۸۹۹',
    ]));

    expect($patient->kod_melli)->toBe('0499370899');
});

test('a sequential duplicate becomes a duplicate validation error, not a 500', function () {
    app(PatientService::class)->create(registryPayload());

    $message = '';

    try {
        app(PatientService::class)->create(registryPayload(['first_name' => 'دیگر']));
        $this->fail('duplicate raise nothing');
    } catch (ValidationException $e) {
        $message = (string) ($e->errors()['duplicate'][0] ?? '');
    }

    expect($message)->toBe('این کد ملی قبلاً ثبت شده است.')
        ->and(Patient::query()->count())->toBe(1)
        ->and(app(ActivityLog::class)->newQuery()
            ->where('subject_type', Patient::class)->count())->toBe(1);
});

test('a failed audit write rolls the patient back', function () {
    // Conditional mechanism (gate: unverified): swap the container request for
    // one whose User-Agent exceeds the varchar(255) `activity_logs.user_agent`
    // column, forcing a 22001 inside ActivityLogService::log().
    $this->app->instance('request', HttpTestRequest::create('/', 'GET', server: [
        'HTTP_USER_AGENT' => str_repeat('x', 300),
    ]));

    $called = false;

    try {
        app(PatientService::class)->create(registryPayload());
    } catch (QueryException) {
        $called = true;
    }

    // The rollback (patients 0) is the atomistic contract; audit failure is the trigger.
    expect($called)->toBeTrue()
        ->and(Patient::query()->count())->toBe(0)
        ->and(app(ActivityLog::class)->newQuery()
            ->where('subject_type', Patient::class)->count())->toBe(0);
});
