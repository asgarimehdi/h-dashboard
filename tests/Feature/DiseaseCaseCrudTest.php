<?php

use App\Models\ActivityLog;
use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\DiseaseFieldDef;
use App\Models\Patient;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    ['user' => $this->actor, 'unit' => $this->actorUnit] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($this->actor);
});

/**
 * A disease with no field defs — the simplest valid case form.
 */
function dc_disease(string $slug = 'htn-crud', array $attrs = []): Disease
{
    return Disease::factory()->create($attrs + [
        'name_fa' => 'فشار خون',
        'slug' => $slug,
        'is_active' => true,
        'sort' => 1,
    ]);
}

/**
 * The §3.4 matrix fixture: two required numbers (mmHg), one select, one text,
 * one optional date.
 */
function dc_required_defs(Disease $disease): void
{
    $defs = [
        ['field_key' => 'sys', 'label_fa' => 'سیستولیک', 'type' => 'number', 'unit' => 'mmHg', 'options' => null, 'required' => true, 'sort' => 1],
        ['field_key' => 'dia', 'label_fa' => 'دیاستولیک', 'type' => 'number', 'unit' => 'mmHg', 'options' => null, 'required' => true, 'sort' => 2],
        ['field_key' => 'stage', 'label_fa' => 'مرحله', 'type' => 'select', 'unit' => null, 'options' => ['I', 'II'], 'required' => false, 'sort' => 3],
        ['field_key' => 'note', 'label_fa' => 'یادداشت', 'type' => 'text', 'unit' => null, 'options' => null, 'required' => false, 'sort' => 4],
        ['field_key' => 'onset', 'label_fa' => 'تاریخ شروع', 'type' => 'date', 'unit' => null, 'options' => null, 'required' => false, 'sort' => 5],
    ];

    foreach ($defs as $def) {
        DiseaseFieldDef::query()->create($def + [
            'disease_id' => $disease->id,
            'is_active' => true,
        ]);
    }
}

function dc_patient(array $attrs = []): Patient
{
    return Patient::factory()->create($attrs + [
        'kod_melli' => '0499370899',
        'first_name' => 'علی',
        'last_name' => 'رضایی',
    ]);
}

/**
 * Open the create form with a patient selected and the disease picked. The
 * demo case is always diagnosed on 1404/07/05 Jalali.
 */
function dc_form(Patient $patient, Disease $disease, array $fields = [])
{
    return Livewire::test('disease.create')
        ->set('kodMelli', $patient->kod_melli)
        ->set('diseaseId', $disease->id)
        ->set('diagnosedAt', '1404/07/05')
        ->set('fields', $fields);
}

// ── create: §8.2 steps 1–6 ────────────────────────────────────────────────

test('the recorder unit comes from the session even when a public unitId is tampered with', function () {
    $disease = dc_disease();
    $patient = dc_patient();
    $otherUnit = Unit::factory()->create();

    dc_form($patient, $disease)
        ->set('unitId', $otherUnit->id)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('disease_cases', [
        'patient_id' => $patient->id,
        'unit_id' => $this->actorUnit->id,
        'subject_unit_id' => $this->actorUnit->id,
    ]);
});

test('the subject unit defaults to the session unit', function () {
    $disease = dc_disease();
    $patient = dc_patient();

    dc_form($patient, $disease)
        ->assertSet('subjectUnitId', $this->actorUnit->id)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('disease_cases', [
        'patient_id' => $patient->id,
        'subject_unit_id' => $this->actorUnit->id,
    ]);
});

test('an accessible descendant unit is accepted as the subject unit', function () {
    $disease = dc_disease();
    $patient = dc_patient();
    $descendant = Unit::factory()->create(['parent_id' => $this->actorUnit->id]);

    dc_form($patient, $disease)
        ->set('subjectUnitId', $descendant->id)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('disease_cases', [
        'patient_id' => $patient->id,
        'subject_unit_id' => $descendant->id,
    ]);
});

test('a subject unit outside the actor scope is rejected without inserting', function () {
    $disease = dc_disease();
    $patient = dc_patient();
    $outside = Unit::factory()->create();

    dc_form($patient, $disease)
        ->set('subjectUnitId', $outside->id)
        ->call('save')
        ->assertHasErrors('subjectUnitId');

    $this->assertDatabaseCount('disease_cases', 0);
});

test('a missing current_unit_id fails closed without inserting', function () {
    $disease = dc_disease();
    $patient = dc_patient();
    $this->actor->units()->detach();
    Session::forget('current_unit_id');

    Livewire::test('disease.create')
        ->set('kodMelli', $patient->kod_melli)
        ->set('diseaseId', $disease->id)
        ->set('diagnosedAt', '1404/07/05')
        ->call('save')
        ->assertHasErrors('subjectUnitId');

    $this->assertDatabaseCount('disease_cases', 0);
});

test('a missing diagnosed date fails validation without inserting', function () {
    $disease = dc_disease();
    $patient = dc_patient();

    dc_form($patient, $disease)
        ->set('diagnosedAt', '')
        ->call('save')
        ->assertHasErrors('diagnosedAt');

    $this->assertDatabaseCount('disease_cases', 0);
});

test('recorded date defaults to today and can be edited', function () {
    $disease = dc_disease();
    $patient = dc_patient();

    $component = dc_form($patient, $disease)
        ->assertSet('recordedAt', Jalalian::fromCarbon(now())->format('Y/m/d'));

    $component->set('recordedAt', '1404/07/01')->call('save')->assertHasNoErrors();

    $expected = Jalalian::fromFormat('Y/m/d', '1404/07/01')->toCarbon()->toDateString();

    $this->assertDatabaseHas('disease_cases', [
        'patient_id' => $patient->id,
        'diagnosed_at' => Jalalian::fromFormat('Y/m/d', '1404/07/05')->toCarbon()->toDateString(),
        'recorded_at' => $expected,
    ]);
});

test('only active diseases are offered and switching disease drops the old field keys', function () {
    $active = dc_disease('htn-active', ['name_fa' => 'فشار خون فعال']);
    dc_required_defs($active);

    $inactive = dc_disease('htn-inactive', ['name_fa' => 'بیماری غیرفعال', 'is_active' => false]);

    $other = dc_disease('anemia-active', ['name_fa' => 'کم خونی']);
    DiseaseFieldDef::query()->create([
        'disease_id' => $other->id,
        'field_key' => 'hgb',
        'label_fa' => 'هموگلوبین',
        'type' => 'number',
        'required' => true,
        'sort' => 1,
        'is_active' => true,
    ]);

    $component = Livewire::test('disease.create');

    $values = array_column($component->instance()->diseaseOptions(), 'value');

    expect($values)->toContain($active->id)
        ->and($values)->not->toContain($inactive->id);

    $component
        ->set('diseaseId', $active->id)
        ->assertSet('fields', ['sys' => '', 'dia' => '', 'stage' => '', 'note' => '', 'onset' => ''])
        ->set('diseaseId', $other->id)
        ->assertSet('fields', ['hgb' => '']);
});

test('number fields carry their unit and select options render with labels', function () {
    $disease = dc_disease();
    dc_required_defs($disease);
    $patient = dc_patient();

    dc_form($patient, $disease)
        ->assertSee('سیستولیک (mmHg)')
        ->assertSee('دیاستولیک (mmHg)')
        ->assertSeeHtml('>II</option>');
});

test('the dynamic field matrix rejects bad input without inserting and never 500s', function (array $fields, string $errorKey) {
    $disease = dc_disease();
    dc_required_defs($disease);
    $patient = dc_patient();

    dc_form($patient, $disease, $fields)
        ->call('save')
        ->assertHasErrors($errorKey);

    $this->assertDatabaseCount('disease_cases', 0);
})->with([
    'required field missing' => [['dia' => 90], 'fields.sys'],
    'number is not numeric' => [['sys' => 'abc', 'dia' => 90], 'fields.sys'],
    'select outside options' => [['sys' => 120, 'dia' => 80, 'stage' => 'nope'], 'fields.stage'],
    'unknown key' => [['sys' => 120, 'dia' => 80, 'mystery' => 'x'], 'fields.mystery'],
    'invalid date' => [['sys' => 120, 'dia' => 80, 'onset' => 'not-a-date'], 'fields.onset'],
]);

test('a duplicate patient-disease-day key becomes a friendly error, not a 500', function () {
    $disease = dc_disease();
    $patient = dc_patient();

    dc_form($patient, $disease)->call('save')->assertHasNoErrors();

    dc_form($patient, $disease)
        ->call('save')
        ->assertHasErrors('diagnosedAt');

    $this->assertDatabaseCount('disease_cases', 1);
});

test('creating a case writes an activity log with the recorder and subject unit', function () {
    $disease = dc_disease();
    $patient = dc_patient();

    dc_form($patient, $disease)->call('save')->assertHasNoErrors();

    $case = DiseaseCase::query()->firstOrFail();

    $log = ActivityLog::query()
        ->where('type', 'created')
        ->where('subject_type', DiseaseCase::class)
        ->where('subject_id', $case->id)
        ->firstOrFail();

    expect($log->user_id)->toBe($this->actor->id)
        ->and($log->new_values['unit_id'])->toBe($this->actorUnit->id)
        ->and($log->new_values['subject_unit_id'])->toBe($this->actorUnit->id);
});

test('an unknown national code opens inline create and the new patient is used for the case', function () {
    $disease = dc_disease();
    dc_required_defs($disease);

    $component = Livewire::test('disease.create')
        ->set('kodMelli', '0013542419')
        ->assertSet('inlinePatient', true);

    $component->set('newPatient', [
        'kod_melli' => '0013542419',
        'first_name' => 'زهرا',
        'last_name' => 'آقایی',
        'birth_date_jalali' => '1370/01/01',
        'gender' => 'female',
        'city' => 'تهران',
    ])->call('createPatient')->assertHasNoErrors();

    $patient = Patient::query()->where('kod_melli', '0013542419')->firstOrFail();

    $component
        ->assertSet('patientId', $patient->id)
        ->assertSet('inlinePatient', false)
        ->set('diseaseId', $disease->id)
        ->set('diagnosedAt', '1404/07/05')
        ->set('fields', ['sys' => 120, 'dia' => 80])
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('disease_cases', ['patient_id' => $patient->id]);

    $this->assertDatabaseHas('activity_logs', [
        'type' => 'created',
        'subject_type' => Patient::class,
        'subject_id' => $patient->id,
        'user_id' => $this->actor->id,
    ]);
});

test('an invalid national code is rejected before a patient row exists', function () {
    $component = Livewire::test('disease.create')
        ->set('kodMelli', '1111111111')
        ->assertSet('inlinePatient', true);

    $component->set('newPatient', [
        'kod_melli' => '1111111111',
        'first_name' => 'بدون',
        'last_name' => 'کد',
        'birth_date_jalali' => '',
        'gender' => 'male',
        'city' => '',
    ])->call('createPatient')->assertHasErrors('newPatient.kod_melli');

    $this->assertDatabaseCount('patients', 0);
});

test('a duplicate national code names the existing patient and creates no second row', function () {
    $existing = dc_patient(['kod_melli' => '0499370899']);

    $component = Livewire::test('disease.create')
        ->set('kodMelli', '0013542419')
        ->assertSet('inlinePatient', true);

    $component->set('newPatient', [
        'kod_melli' => '0499370899',
        'first_name' => 'دیگر',
        'last_name' => 'نفر',
        'birth_date_jalali' => '',
        'gender' => 'male',
        'city' => '',
    ])
        ->call('createPatient')
        ->assertHasErrors('newPatient.kod_melli')
        ->assertSee($existing->fullName());

    $this->assertDatabaseCount('patients', 1);
});

test('persian digits in the national code find the latin patient', function () {
    $patient = dc_patient(['kod_melli' => '0013542419']);

    Livewire::test('disease.create')
        ->set('kodMelli', '۰۰۱۳۵۴۲۴۱۹')
        ->assertSet('patientId', $patient->id);
});

test('phase two smoke: a disease and field created from the manage UI are usable on the case form', function () {
    Livewire::test('disease.manage')
        ->call('startCreateDisease')
        ->set('nameFa', 'بیماری دودی')
        ->set('slug', 'smoke-disease')
        ->call('saveDisease')
        ->assertHasNoErrors();

    $disease = Disease::query()->where('slug', 'smoke-disease')->firstOrFail();

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'sys')
        ->set('labelFa', 'سیستولیک')
        ->set('type', 'number')
        ->set('unit', 'mmHg')
        ->set('isRequired', true)
        ->set('fieldSort', 1)
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    $patient = dc_patient();

    dc_form($patient, $disease, ['sys' => 130])
        ->call('save')
        ->assertHasNoErrors();

    $case = DiseaseCase::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($case->fields['sys'])->toBe(130);
});
