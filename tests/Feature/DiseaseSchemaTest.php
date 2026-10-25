<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'patients' => ['id', 'kod_melli', 'first_name', 'last_name', 'birth_date', 'gender', 'city', 'created_at', 'updated_at'],
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
