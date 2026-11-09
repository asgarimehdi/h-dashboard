<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\DiseaseFieldDef;
use App\Models\Patient;
use App\Models\Unit;
use App\Models\UnitPopulation;
use Database\Seeders\DiseaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

// Phase 1 of the disease registration feature (#1240): schema only.
// Heavy behaviour (dynamic field validation, visibility, aggregation) belongs
// to later phases and is deliberately not exercised here.

test('disease schema tables and key columns exist', function () {
    $tables = [
        'patients',
        'diseases',
        'disease_field_defs',
        'disease_cases',
        'unit_populations',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");
    }

    $columns = [
        'patients' => ['id', 'kod_melli', 'first_name', 'last_name', 'birth_date', 'gender', 'created_at', 'updated_at'],
        'diseases' => ['id', 'name_fa', 'slug', 'is_active', 'sort', 'created_at', 'updated_at'],
        'disease_field_defs' => ['id', 'disease_id', 'field_key', 'label_fa', 'type', 'unit', 'options', 'required', 'sort', 'is_active', 'created_at', 'updated_at'],
        'disease_cases' => ['id', 'patient_id', 'disease_id', 'unit_id', 'subject_unit_id', 'diagnosed_at', 'recorded_at', 'fields', 'created_at', 'updated_at'],
        'unit_populations' => ['id', 'unit_id', 'year', 'population', 'created_at', 'updated_at'],
    ];

    foreach ($columns as $table => $expected) {
        foreach ($expected as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("missing column: {$table}.{$column}");
        }
    }

    expect(Schema::hasColumn('patients', 'deleted_at'))->toBeFalse();
    expect(Schema::hasColumn('patients', 'city'))->toBeFalse();
});

test('disease_cases.fields defaults to an empty jsonb object at the database level', function () {
    $patientId = DB::table('patients')->insertGetId([
        'kod_melli' => '0000000001',
        'first_name' => 'تست',
        'last_name' => 'تست',
        'gender' => 'male',
    ]);
    $diseaseId = DB::table('diseases')->insertGetId([
        'name_fa' => 'فشار خون',
        'slug' => 'htn',
    ]);
    $unitId = DB::table('units')->insertGetId(['name' => 'واحد تست']);

    $caseId = DB::table('disease_cases')->insertGetId([
        'patient_id' => $patientId,
        'disease_id' => $diseaseId,
        'unit_id' => $unitId,
        'subject_unit_id' => $unitId,
        'diagnosed_at' => '2026-10-20',
        'recorded_at' => '2026-10-21',
    ]);

    $raw = DB::selectOne('SELECT fields::text AS fields FROM disease_cases WHERE id = ?', [$caseId]);

    expect($raw->fields)->toBe('{}');
});

test('patients.kod_melli is unique at the database level', function () {
    $row = [
        'kod_melli' => '1234567890',
        'first_name' => 'تست',
        'last_name' => 'تست',
        'gender' => 'female',
    ];

    DB::table('patients')->insert($row);

    expect(fn () => DB::table('patients')->insert($row))
        ->toThrow(QueryException::class);
});

test('disease_cases rejects a duplicate patient, disease and day at the database level', function () {
    $patientId = DB::table('patients')->insertGetId([
        'kod_melli' => '0000000002',
        'first_name' => 'تست',
        'last_name' => 'تست',
        'gender' => 'male',
    ]);
    $diseaseId = DB::table('diseases')->insertGetId([
        'name_fa' => 'دیابت',
        'slug' => 'diabetes',
    ]);
    $unitId = DB::table('units')->insertGetId(['name' => 'واحد تست']);

    $row = [
        'patient_id' => $patientId,
        'disease_id' => $diseaseId,
        'unit_id' => $unitId,
        'subject_unit_id' => $unitId,
        'diagnosed_at' => '2026-10-20',
        'recorded_at' => '2026-10-21',
    ];

    DB::table('disease_cases')->insert($row);

    expect(fn () => DB::table('disease_cases')->insert($row))
        ->toThrow(QueryException::class);
});

test('patient, disease and case factories produce valid rows through the models', function () {
    $patient = Patient::factory()->create();
    $disease = Disease::factory()->create();
    $case = DiseaseCase::factory()->create();

    $this->assertDatabaseHas('patients', ['id' => $patient->id]);
    $this->assertDatabaseHas('diseases', ['id' => $disease->id, 'is_active' => true]);
    $this->assertDatabaseHas('disease_cases', [
        'id' => $case->id,
        'patient_id' => $case->patient_id,
        'disease_id' => $case->disease_id,
    ]);

    expect($patient->kod_melli)->toHaveLength(10)
        ->and($patient->gender)->toBeIn(['male', 'female'])
        ->and($disease->is_active)->toBeTrue()
        ->and($case->fields)->toBe([])
        ->and($case->subject_unit_id)->toBe($case->unit_id)
        ->and($case->diagnosed_at)->toBeInstanceOf(Carbon::class)
        ->and($case->recorded_at)->toBeInstanceOf(Carbon::class)
        ->and($case->patient->id)->toBe($case->patient_id)
        ->and($case->disease->id)->toBe($case->disease_id)
        ->and($case->unit->id)->toBe($case->unit_id)
        ->and($case->subjectUnit->id)->toBe($case->subject_unit_id);
});

test('disease factory slugs stay unique across several rows', function () {
    Disease::factory()->count(5)->create();

    expect(Disease::query()->count())->toBe(5)
        ->and(Disease::query()->distinct()->count('slug'))->toBe(5);
});

test('model relations expose cases, field defs and populations', function () {
    $case = DiseaseCase::factory()->create();
    $population = UnitPopulation::create([
        'unit_id' => Unit::factory()->create()->id,
        'year' => 1404,
        'population' => 12000,
    ]);

    expect($case->patient->diseaseCases()->whereKey($case->id)->exists())->toBeTrue()
        ->and($case->disease->diseaseCases()->whereKey($case->id)->exists())->toBeTrue()
        ->and($population->unit)->toBeInstanceOf(Unit::class);
});

test('DiseaseSeeder seeds five diseases and seven field definitions idempotently', function () {
    $this->seed(DiseaseSeeder::class);

    expect(Disease::query()->count())->toBe(5)
        ->and(DiseaseFieldDef::query()->count())->toBe(7);

    $htn = Disease::query()->where('slug', 'htn')->firstOrFail();
    expect($htn->name_fa)->toBe('فشار خون')
        ->and($htn->sort)->toBe(1)
        ->and($htn->is_active)->toBeTrue()
        ->and($htn->fieldDefs()->orderBy('sort')->pluck('field_key')->all())->toBe(['sys', 'dia'])
        ->and($htn->fieldDefs()->where('field_key', 'sys')->value('unit'))->toBe('mmHg')
        ->and($htn->fieldDefs()->where('field_key', 'sys')->value('required'))->toBeTrue();

    $thalassemiaType = Disease::query()->where('slug', 'thalassemia')->firstOrFail()
        ->fieldDefs()->where('field_key', 'type')->firstOrFail();
    expect($thalassemiaType->options)->toBe(['β/thal', 'δβ', 'α/thal'])
        ->and($thalassemiaType->unit)->toBeNull()
        ->and($thalassemiaType->is_active)->toBeTrue();

    expect(Disease::query()->where('slug', 'cancer')->firstOrFail()
        ->fieldDefs()->where('field_key', 'stage')->value('options'))
        ->toBe(['I', 'II', 'III', 'IV']);

    expect(Disease::query()->where('slug', 'hiv')->firstOrFail()
        ->fieldDefs()->where('field_key', 'cd4')->value('required'))->toBeFalse();

    // DatabaseSeeder runs in every environment, so a second run must not
    // duplicate rows nor violate a unique key.
    $this->seed(DiseaseSeeder::class);

    expect(Disease::query()->count())->toBe(5)
        ->and(DiseaseFieldDef::query()->count())->toBe(7);
});
