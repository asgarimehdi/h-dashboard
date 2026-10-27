<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\DiseaseFieldDef;
use App\Models\Patient;
use App\Models\Unit;
use App\Services\PatientService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1243 — patient registry: the write path, the two search axes, the detail
 * panel and the inline-create flow.
 *
 * Claims are asserted on the DATABASE, never on component status (AGENTS.md).
 * Faker names are pinned wherever an `assertDontSee` is involved.
 */
class PatientSearchTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_diseases']);
        $this->unit = $unit;
        $this->actingAs($user);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'kod_melli' => '0499370899',
            'first_name' => 'آرش',
            'last_name' => 'حسن‌زاده',
            'birth_date_jalali' => '1370/01/01',
            'gender' => 'male',
        ], $overrides);
    }

    // ==================== route + authorization ====================

    public function test_the_registry_route_requires_manage_diseases(): void
    {
        $without = $this->createUserWithUnit([])['user'];

        $this->actingAs($without)
            ->get('/disease/patients')
            ->assertStatus(403);
    }

    public function test_the_component_authorizes_on_mount(): void
    {
        $without = $this->createUserWithUnit([])['user'];
        $this->actingAs($without);

        // Livewire's test broker propagates the AuthorizationException as a 403
        // response (the same page the browser gets), not as a thrown exception.
        Livewire::test('disease.patients')->assertForbidden();
    }

    public function test_an_authorized_user_can_open_the_registry(): void
    {
        $this->get('/disease/patients')->assertOk();
    }

    // ==================== search ====================

    /** @param array<string, mixed> $overrides */
    private function makePatient(array $overrides = []): Patient
    {
        return app(PatientService::class)->create($this->payload($overrides));
    }

    public function test_searching_by_the_full_national_code_matches_exactly(): void
    {
        $this->makePatient(['kod_melli' => '0499370899', 'first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);
        $this->makePatient(['kod_melli' => '0013542419', 'first_name' => 'رضا', 'last_name' => 'محمدی']);

        Livewire::test('disease.patients')
            ->set('searchCode', '0499370899')
            ->assertSee('حسن‌زاده')
            ->assertDontSee('محمدی');
    }

    public function test_a_code_prefix_matches_nothing(): void
    {
        $this->makePatient(['kod_melli' => '0499370899', 'first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);

        Livewire::test('disease.patients')
            ->set('searchCode', '0499370')
            ->assertSee('کد ملی ۱۰ رقم است')
            ->assertDontSee('حسن‌زاده');
    }

    public function test_persian_digits_find_the_same_patient(): void
    {
        $this->makePatient(['kod_melli' => '0499370899', 'first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);

        Livewire::test('disease.patients')
            ->set('searchCode', '۰۴۹۹۳۷۰۸۹۹')
            ->assertSee('حسن‌زاده');
    }

    public function test_name_search_folds_persian_variants_and_zwnj(): void
    {
        $this->makePatient(['first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);
        $this->makePatient([
            'kod_melli' => '0013542419',
            'first_name' => 'سعید',
            'last_name' => 'اکبری',
        ]);

        Livewire::test('disease.patients')
            ->set('searchName', 'ارش حسن زاده')
            ->assertSee('حسن‌زاده')
            ->assertDontSee('اکبری');
    }

    public function test_every_name_term_must_match(): void
    {
        $this->makePatient(['first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);
        $this->makePatient([
            'kod_melli' => '0013542419',
            'first_name' => 'آرش',
            'last_name' => 'اکبری',
        ]);

        Livewire::test('disease.patients')
            ->set('searchName', 'ارش اکبری')
            ->assertSee('اکبری')
            ->assertDontSee('حسن‌زاده');

        Livewire::test('disease.patients')
            ->set('searchName', 'ارش رضایی')
            ->assertSee('بیماری با این مشخصات یافت نشد')
            ->assertDontSee('حسن‌زاده')
            ->assertDontSee('اکبری');
    }

    public function test_the_page_dumps_no_patients_before_a_search(): void
    {
        $this->makePatient(['first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);

        Livewire::test('disease.patients')
            ->assertDontSee('حسن‌زاده')
            ->assertSee('برای دیدن پرونده');
    }

    public function test_the_code_and_name_inputs_are_combined_with_and(): void
    {
        $this->makePatient(['kod_melli' => '0499370899', 'first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);
        $this->makePatient([
            'kod_melli' => '0013542419',
            'first_name' => 'رضا',
            'last_name' => 'محمدی',
        ]);

        Livewire::test('disease.patients')
            ->set('searchCode', '0499370899')
            ->set('searchName', 'اکبری')
            ->assertDontSee('حسن‌زاده')
            ->assertDontSee('محمدی');
    }

    // ==================== detail panel ====================

    private function openPanel(Patient $patient): Testable
    {
        return Livewire::test('disease.patients')->call('selectPatient', $patient->id);
    }

    /** @param array<string, mixed> $overrides */
    private function makeCase(Patient $patient, Disease $disease, array $overrides = []): DiseaseCase
    {
        return DiseaseCase::factory()->create(array_merge([
            'patient_id' => $patient->id,
            'disease_id' => $disease->id,
            'unit_id' => $this->unit->id,
            'subject_unit_id' => $this->unit->id,
            'diagnosed_at' => '2026-01-02',
            'recorded_at' => '2026-01-03',
            'fields' => [],
        ], $overrides));
    }

    public function test_the_detail_panel_shows_the_four_identity_fields(): void
    {
        $patient = $this->makePatient(['gender' => 'female']);

        $this->openPanel($patient)
            ->assertSet('selectedPatientId', $patient->id)
            ->assertSee('آرش')
            ->assertSee('حسن‌زاده')
            ->assertSee('0499370899')
            ->assertSee(Jalalian::fromCarbon(Carbon::parse('1991-03-21'))->format('Y/m/d'))
            ->assertSeeHtml('جنسیت:</span> زن')
            ->assertDontSee('شهرستان');
    }

    public function test_the_detail_panel_lists_cases_label_first(): void
    {
        $patient = $this->makePatient();

        $disease = Disease::factory()->create(['name_fa' => 'دیابت نوع دو']);
        DiseaseFieldDef::query()->create([
            'disease_id' => $disease->id,
            'field_key' => 'bp_systolic',
            'label_fa' => 'فشار سیستولیک',
            'type' => 'number',
            'unit' => 'mmHg',
            'required' => false,
            'sort' => 1,
            'is_active' => true,
        ]);

        $this->makeCase($patient, $disease, ['fields' => ['bp_systolic' => 140]]);

        $this->openPanel($patient)
            ->assertSee('دیابت نوع دو')
            ->assertSee('فشار سیستولیک')
            ->assertSeeHtml('>140<')
            ->assertSee('mmHg')
            ->assertSee($this->unit->name)
            ->assertSee(Jalalian::fromCarbon(Carbon::parse('2026-01-02'))->format('Y/m/d'))
            ->assertSee(Jalalian::fromCarbon(Carbon::parse('2026-01-03'))->format('Y/m/d'))
            ->assertDontSee('bp_systolic');
    }

    public function test_a_case_outside_the_unit_scope_is_hidden(): void
    {
        $patient = $this->makePatient();

        $visibleDisease = Disease::factory()->create(['name_fa' => 'دیابت']);
        $hiddenDisease = Disease::factory()->create(['name_fa' => 'سرطان ریه']);

        $this->makeCase($patient, $visibleDisease);

        $otherUnit = Unit::factory()->create();
        DiseaseCase::factory()->create([
            'patient_id' => $patient->id,
            'disease_id' => $hiddenDisease->id,
            'unit_id' => $otherUnit->id,
            'subject_unit_id' => $otherUnit->id,
            'fields' => [],
        ]);

        $this->openPanel($patient)
            ->assertSee('دیابت')
            ->assertDontSee('سرطان ریه');
    }

    public function test_the_panel_says_nothing_is_available_when_no_case_is_in_scope(): void
    {
        $patient = $this->makePatient();

        $this->openPanel($patient)->assertSee('موردی در دسترس شما نیست');
    }

    public function test_closing_the_panel_clears_the_selection(): void
    {
        $patient = $this->makePatient();

        $this->openPanel($patient)
            ->call('closePatient')
            ->assertSet('selectedPatientId', null)
            ->assertDontSee('0499370899');
    }

    // ==================== inline create ====================

    public function test_the_inline_create_flow_prefills_the_searched_code_and_opens_the_panel(): void
    {
        $component = Livewire::test('disease.patients')
            ->set('searchCode', '0499370899')
            ->assertSee('ثبت بیمار جدید')
            ->call('openCreateForm')
            ->assertSet('formOpen', true)
            ->assertSet('kod_melli', '0499370899')
            ->set('first_name', 'آرش')
            ->set('last_name', 'حسن‌زاده')
            ->set('birth_date_jalali', '1370/01/01')
            ->set('gender', 'male')
            ->call('savePatient')
            ->assertHasNoErrors()
            ->assertSet('formOpen', false);

        $patient = Patient::query()->where('kod_melli', '0499370899')->firstOrFail();

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'first_name' => 'آرش',
            'last_name' => 'حسن‌زاده',
            'gender' => 'male',
        ]);
        $this->assertSame('1991-03-21', $patient->birth_date?->format('Y-m-d'));

        $component->assertSet('selectedPatientId', $patient->id)
            ->assertSee('0499370899')
            ->assertSeeHtml('جنسیت:</span> مرد');
    }

    public function test_saving_from_the_form_normalizes_persian_digits(): void
    {
        Livewire::test('disease.patients')
            ->call('openCreateForm')
            ->set('kod_melli', '۰۴۹۹۳۷۰۸۹۹')
            ->set('first_name', '  آرش  ')
            ->set('last_name', 'حسن‌زاده')
            ->set('gender', 'female')
            ->call('savePatient')
            ->assertHasNoErrors();

        // Leading zero preserved; the name is trimmed.
        $this->assertDatabaseHas('patients', [
            'kod_melli' => '0499370899',
            'first_name' => 'آرش',
            'last_name' => 'حسن‌زاده',
            'gender' => 'female',
        ]);
    }

    public function test_a_duplicate_national_code_shows_the_existing_patient_and_creates_no_row(): void
    {
        $existing = $this->makePatient(['first_name' => 'آرش', 'last_name' => 'حسن‌زاده']);

        Livewire::test('disease.patients')
            ->call('openCreateForm')
            ->set('kod_melli', '0499370899')
            ->set('first_name', 'سعید')
            ->set('last_name', 'اکبری')
            ->set('gender', 'male')
            ->call('savePatient')
            ->assertHasErrors('kod_melli')
            ->assertSee('آرش حسن‌زاده')
            ->assertSee('مشاهدهٔ پرونده')
            ->assertSet('duplicatePatientId', $existing->id)
            ->call('openDuplicatePatient')
            ->assertSet('formOpen', false)
            ->assertSet('selectedPatientId', $existing->id)
            ->assertSee('0499370899');

        $this->assertSame(1, Patient::query()->count());
    }

    public function test_an_invalid_checksum_is_rejected_without_creating_a_row(): void
    {
        Livewire::test('disease.patients')
            ->call('openCreateForm')
            ->set('kod_melli', '0012345678')
            ->set('first_name', 'آرش')
            ->set('last_name', 'حسن‌زاده')
            ->set('gender', 'male')
            ->call('savePatient')
            ->assertHasErrors('kod_melli');

        $this->assertSame(0, Patient::query()->count());
    }

    public function test_a_repeated_digit_code_is_rejected(): void
    {
        Livewire::test('disease.patients')
            ->call('openCreateForm')
            ->set('kod_melli', '1111111111')
            ->set('first_name', 'آرش')
            ->set('last_name', 'حسن‌زاده')
            ->set('gender', 'male')
            ->call('savePatient')
            ->assertHasErrors('kod_melli');

        $this->assertSame(0, Patient::query()->count());
    }

    public function test_the_required_identity_fields_are_validated(): void
    {
        Livewire::test('disease.patients')
            ->call('openCreateForm')
            ->set('kod_melli', '0499370899')
            ->call('savePatient')
            ->assertHasErrors(['first_name', 'last_name', 'gender'])
            ->assertHasNoErrors(['birth_date_jalali']);

        $this->assertSame(0, Patient::query()->count());
    }

    public function test_the_registry_page_and_modal_no_longer_offer_a_county_field(): void
    {
        $patient = Patient::factory()->create([
            'kod_melli' => '0499370899',
            'first_name' => 'آرش',
            'last_name' => 'حسن‌زاده',
        ]);

        Livewire::test('disease.patients')
            ->assertDontSee('شهرستان')
            ->call('selectPatient', id: $patient->id)
            ->assertDontSee('شهرستان');
    }

    public function test_a_future_birth_date_is_rejected_by_the_form(): void
    {
        $future = Jalalian::fromCarbon(now()->addDay())->format('Y/m/d');

        Livewire::test('disease.patients')
            ->call('openCreateForm')
            ->set('kod_melli', '0499370899')
            ->set('first_name', 'آرش')
            ->set('last_name', 'حسن‌زاده')
            ->set('gender', 'male')
            ->set('birth_date_jalali', $future)
            ->call('savePatient')
            ->assertHasErrors('birth_date_jalali');

        $this->assertSame(0, Patient::query()->count());
    }

    // ==================== PatientService — write path ====================

    public function test_create_stores_latin_digits_and_a_gregorian_birth_date(): void
    {
        $patient = app(PatientService::class)->create($this->payload([
            'kod_melli' => '۰۴۹۹۳۷۰۸۹۹',
        ]));

        // Leading zero preserved (the column is string(10)), Persian digits folded.
        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'kod_melli' => '0499370899',
            'first_name' => 'آرش',
            'last_name' => 'حسن‌زاده',
            'gender' => 'male',
        ]);
        $this->assertSame('1991-03-21', $patient->fresh()?->birth_date?->format('Y-m-d'));
    }

    public function test_create_turns_empty_optional_strings_into_null(): void
    {
        $patient = app(PatientService::class)->create($this->payload([
            'birth_date_jalali' => '',
        ]));

        $fresh = $patient->fresh();
        $this->assertNull($fresh?->birth_date);
    }

    public function test_create_writes_an_audit_row_without_name_or_national_code(): void
    {
        $patient = app(PatientService::class)->create($this->payload());

        $logs = ActivityLog::query()
            ->where('subject_type', Patient::class)
            ->where('subject_id', $patient->id)
            ->where('type', 'created')
            ->get();

        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertNotNull($log);

        $description = (string) $log->description;
        $this->assertStringNotContainsString('آرش', $description);
        $this->assertStringNotContainsString('حسن‌زاده', $description);
        $this->assertStringNotContainsString('0499370899', $description);
        $this->assertStringNotContainsString('0499370899', (string) json_encode($log->new_values));
    }

    public function test_find_by_kod_melli_accepts_both_digit_shapes(): void
    {
        $patient = app(PatientService::class)->create($this->payload());
        $service = app(PatientService::class);

        $this->assertSame($patient->id, $service->findByKodMelli('0499370899')?->id);
        $this->assertSame($patient->id, $service->findByKodMelli('۰۴۹۹۳۷۰۸۹۹')?->id);
        $this->assertNull($service->findByKodMelli('0499370890'));
    }

    public function test_duplicate_message_names_the_existing_patient(): void
    {
        $patient = app(PatientService::class)->create($this->payload());

        $message = PatientService::duplicateMessage($patient);

        $this->assertStringContainsString('آرش حسن‌زاده', $message);
        $this->assertStringContainsString('مشاهدهٔ پرونده', $message);
    }

    public function test_model_helpers_render_the_shared_labels(): void
    {
        $patient = app(PatientService::class)->create($this->payload(['gender' => 'female']));

        $this->assertSame('آرش حسن‌زاده', $patient->fullName());
        $this->assertSame('زن', $patient->genderLabel());

        $male = app(PatientService::class)->create($this->payload([
            'kod_melli' => '0013542419',
            'first_name' => 'رضا',
            'last_name' => 'محمدی',
        ]));

        $this->assertSame('مرد', $male->genderLabel());
    }

    // ==================== shared validation rules ====================

    public function test_validation_rules_reject_a_future_birth_date(): void
    {
        $future = Jalalian::fromCarbon(now()->addDay())->format('Y/m/d');

        $validator = Validator::make(
            $this->payload(['birth_date_jalali' => $future]),
            PatientService::validationRules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('birth_date_jalali', $validator->errors()->toArray());
    }

    public function test_validation_rules_accept_an_empty_birth_date(): void
    {
        $validator = Validator::make(
            $this->payload(['birth_date_jalali' => '']),
            PatientService::validationRules(),
        );

        $this->assertFalse($validator->fails());
    }

    public function test_validation_rules_require_the_identity_fields(): void
    {
        $validator = Validator::make(
            ['kod_melli' => '', 'first_name' => '', 'last_name' => '', 'gender' => ''],
            PatientService::validationRules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('kod_melli', $validator->errors()->toArray());
        $this->assertArrayHasKey('first_name', $validator->errors()->toArray());
        $this->assertArrayHasKey('last_name', $validator->errors()->toArray());
        $this->assertArrayHasKey('gender', $validator->errors()->toArray());
        $this->assertArrayNotHasKey('birth_date_jalali', $validator->errors()->toArray());
    }
}
