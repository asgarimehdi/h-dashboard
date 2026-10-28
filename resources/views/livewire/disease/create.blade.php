<?php

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Patient;
use App\Models\Unit;
use App\Rules\AccessibleUnit;
use App\Services\AccessService;
use App\Services\Disease\DiseaseCaseService;
use App\Services\PatientService;
use App\Support\JalaliDateRange;
use App\Traits\BuildsDiseaseCaseForm;
use App\Traits\PersianNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Mary\Traits\Toast;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use BuildsDiseaseCaseForm;
    use Toast;

    // ── step 1: patient ──
    public string $kodMelli = '';

    public ?int $patientId = null;

    /** @var array{id: int, kod_melli: string, first_name: string, last_name: string}|null */
    public ?array $patientPreview = null;

    public bool $inlinePatient = false;

    /** @var array<string, string> */
    public array $newPatient = [];

    // ── step 2: disease ──
    public ?int $diseaseId = null;

    // ── step 4: dates (Jalali in the UI, Gregorian in the DB) ──
    public string $diagnosedAt = '';

    public string $recordedAt = '';

    // ── step 5: subject unit ──
    public ?int $subjectUnitId = null;

    public bool $unitModal = false;

    /**
     * Client-controlled and deliberately ignored: the recorder unit is
     * `session('current_unit_id')`, never a form property. It exists only so a
     * tampered payload is explicitly exercised by the tests.
     */
    public ?int $unitId = null;

    public function mount(): void
    {
        $this->authorize('manage_diseases');

        $this->recordedAt = Jalalian::fromCarbon(\Carbon\Carbon::now())->format('Y/m/d');
        $this->newPatient = $this->blankNewPatient();

        $sessionUnit = session('current_unit_id');
        $this->subjectUnitId = $sessionUnit !== null && $sessionUnit !== '' ? (int) $sessionUnit : null;
    }

    /**
     * Step 1: exact national-code lookup. Persian/Arabic digits are folded
     * first, otherwise a code typed on a Persian keyboard never matches.
     */
    public function updatedKodMelli(): void
    {
        $this->authorize('manage_diseases');

        $code = trim(PersianNormalizer::normalizeForSearch($this->kodMelli));
        $this->kodMelli = $code;

        if ($code === '') {
            $this->patientId = null;
            $this->patientPreview = null;
            $this->inlinePatient = false;

            return;
        }

        $patient = Patient::query()
            ->where('kod_melli', $code)
            ->first(['id', 'kod_melli', 'first_name', 'last_name']);

        if ($patient instanceof Patient) {
            $this->patientId = $patient->id;
            $this->patientPreview = $this->preview($patient);
            $this->inlinePatient = false;

            return;
        }

        $this->patientId = null;
        $this->patientPreview = null;

        // A ten-digit code we do not know is the inline-create candidate. The
        // checksum is checked by `createPatient()`, not here, so the user sees
        // the real validation message instead of an unexplained missing form.
        $this->inlinePatient = preg_match('/^\d{10}$/', $code) === 1;
        $this->newPatient['kod_melli'] = $this->inlinePatient ? $code : '';
    }

    /**
     * Step 1 (inline create, decision 6/ج). `PatientService` is the shared
     * write path from #1243 — its rules already carry `IranianNationalCode`.
     */
    public function createPatient(): void
    {
        $this->authorize('manage_diseases');

        $this->newPatient['kod_melli'] = trim(PersianNormalizer::normalizeForSearch(
            (string) ($this->newPatient['kod_melli'] ?? ''),
        ));

        $rules = [];

        foreach (PatientService::validationRules() as $key => $rule) {
            $rules["newPatient.{$key}"] = $rule;
        }

        $this->validate($rules);

        $service = app(PatientService::class);
        $existing = $service->findByKodMelli($this->newPatient['kod_melli']);

        if ($existing instanceof Patient) {
            $this->addError('newPatient.kod_melli', PatientService::duplicateMessage($existing));

            return;
        }

        try {
            $patient = $service->create([
                'kod_melli' => $this->newPatient['kod_melli'],
                'first_name' => $this->newPatient['first_name'] ?? '',
                'last_name' => $this->newPatient['last_name'] ?? '',
                'birth_date_jalali' => $this->newPatient['birth_date_jalali'] ?? '',
                'gender' => $this->newPatient['gender'] ?? '',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Race: the row appeared between the lookup and the insert.
            $existing = $service->findByKodMelli($this->newPatient['kod_melli']);
            $this->addError(
                'newPatient.kod_melli',
                $existing instanceof Patient
                    ? PatientService::duplicateMessage($existing)
                    : 'این کد ملی قبلاً ثبت شده است.',
            );

            return;
        }

        $this->patientId = $patient->id;
        $this->patientPreview = $this->preview($patient);
        $this->kodMelli = $patient->kod_melli;
        $this->inlinePatient = false;
        $this->newPatient = $this->blankNewPatient();
        $this->resetValidation();
        $this->success('بیمار ثبت شد.', position: 'toast-bottom');
    }

    /**
     * Step 2: only active diseases are offered on a NEW case (decision 2).
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function diseaseOptions(): array
    {
        return Disease::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('name_fa')
            ->get(['id', 'name_fa'])
            ->map(static fn (mixed $disease): array => [
                'value' => (int) data_get($disease, 'id'),
                'label' => (string) data_get($disease, 'name_fa'),
            ])
            ->all();
    }

    /**
     * Step 3: a disease switch discards the previous disease's keys, otherwise
     * an unknown key would travel to the service.
     */
    public function updatedDiseaseId(): void
    {
        $this->authorize('manage_diseases');

        $this->fields = $this->emptyFieldsFor($this->diseaseId);
        $this->resetValidation();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function genderOptions(): array
    {
        return [
            ['value' => 'male', 'label' => 'مرد'],
            ['value' => 'female', 'label' => 'زن'],
        ];
    }

    /**
     * The render-on-open contract: this is only called when the modal is open
     * (see the Blade view), and it is a computed so the tree never travels in
     * the snapshot. `whereIn` is unconditional: `[]` renders an empty picker.
     *
     * @return array<int, array{id: int, name: string, parent_id: int|null, unit_type_name: string|null}>
     */
    #[Computed]
    public function unitTree(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        return Unit::query()
            ->with('unitType')
            ->whereIn('id', $accessibleIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (mixed $unit): array => [
                'id' => (int) data_get($unit, 'id'),
                'name' => (string) data_get($unit, 'name'),
                'parent_id' => data_get($unit, 'parent_id') !== null ? (int) data_get($unit, 'parent_id') : null,
                'unit_type_name' => data_get($unit, 'unitType.name') !== null ? (string) data_get($unit, 'unitType.name') : null,
            ])
            ->all();
    }

    #[Computed]
    public function selectedUnitName(): ?string
    {
        if ($this->subjectUnitId === null) {
            return null;
        }

        return Unit::query()
            ->whereKey($this->subjectUnitId)
            ->whereIn('id', app(AccessService::class)->accessibleUnitIds())
            ->value('name');
    }

    /**
     * Step 6: the single write path. Order is contractual — authorize, session
     * recorder, validation, service (client never picks `unit_id`).
     */
    public function save(): void
    {
        $this->authorize('manage_diseases');

        $sessionUnit = session('current_unit_id');

        if ($sessionUnit === null || $sessionUnit === '') {
            // Livewire::test bypasses ValidateUnitContext, so this guard is the
            // only thing keeping `unit_id = null` out of the database.
            $this->addError('subjectUnitId', 'واحد جاری انتخاب نشده است.');
            $this->error('واحد جاری انتخاب نشده است.', position: 'toast-bottom');

            return;
        }

        $recorderUnitId = (int) $sessionUnit;

        $this->fields = $this->nonEmptyFields($this->fields);

        $this->validate([
            'patientId' => ['required', 'integer', Rule::exists('patients', 'id')],
            'diseaseId' => ['required', 'integer', Rule::exists('diseases', 'id')->where('is_active', true)],
            'diagnosedAt' => ['required', 'string', $this->jalaliDateRule()],
            'recordedAt' => ['required', 'string', $this->jalaliDateRule()],
            'subjectUnitId' => ['required', 'integer', new AccessibleUnit],
        ] + $this->fieldRules($this->diseaseId));

        $diagnosedAt = JalaliDateRange::parse($this->diagnosedAt);
        $recordedAt = JalaliDateRange::parse($this->recordedAt);

        if ($diagnosedAt === null || $recordedAt === null) {
            // Unreachable after the rules above; keeps the types honest.
            $this->addError('diagnosedAt', 'تاریخ واردشده معتبر نیست.');

            return;
        }

        $diagnosedOn = $diagnosedAt->toDateString();

        $duplicate = DiseaseCase::query()
            ->where('patient_id', $this->patientId)
            ->where('disease_id', $this->diseaseId)
            ->whereDate('diagnosed_at', $diagnosedOn)
            ->exists();

        if ($duplicate) {
            $this->duplicateWarning();

            return;
        }

        try {
            app(DiseaseCaseService::class)->create([
                'patient_id' => (int) $this->patientId,
                'disease_id' => (int) $this->diseaseId,
                'subject_unit_id' => (int) $this->subjectUnitId,
                'diagnosed_at' => $diagnosedOn,
                'recorded_at' => $recordedAt->toDateString(),
                'fields' => $this->fieldsForService($this->fields, $this->diseaseId),
            ], $recorderUnitId, app(AccessService::class)->accessibleUnitIds());
        } catch (ValidationException $e) {
            // The service is the §3.4 authority (required/type/select/date/
            // unknown key). Nothing is re-validated here — errors are mapped.
            if (array_key_exists('duplicate', $e->errors())) {
                $this->duplicateWarning();

                return;
            }

            $this->mapServiceValidationErrors($e);

            return;
        }

        $this->success('کیس بیماری ثبت شد.', position: 'toast-bottom');
        $this->resetForm($recorderUnitId);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'defs' => $this->fieldDefsFor($this->diseaseId),
            'selectOptions' => $this->selectOptionsMap($this->diseaseId),
        ];
    }

    private function duplicateWarning(): void
    {
        $this->addError('diagnosedAt', $this->duplicateCaseMessage());
        $this->warning($this->duplicateCaseMessage(), position: 'toast-bottom');
    }

    private function jalaliDateRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || JalaliDateRange::parse($value) === null) {
                $fail('تاریخ واردشده معتبر نیست.');
            }
        };
    }

    /**
     * @return array{id: int, kod_melli: string, first_name: string, last_name: string}
     */
    private function preview(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'kod_melli' => $patient->kod_melli,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function blankNewPatient(): array
    {
        return [
            'kod_melli' => '',
            'first_name' => '',
            'last_name' => '',
            'birth_date_jalali' => '',
            'gender' => '',
        ];
    }

    private function resetForm(int $recorderUnitId): void
    {
        $this->reset([
            'kodMelli',
            'patientId',
            'patientPreview',
            'inlinePatient',
            'diseaseId',
            'fields',
            'diagnosedAt',
            'recordedAt',
            'unitId',
        ]);

        $this->newPatient = $this->blankNewPatient();
        $this->recordedAt = Jalalian::fromCarbon(\Carbon\Carbon::now())->format('Y/m/d');
        // The next case usually belongs to the same patient unit.
        $this->subjectUnitId = $recorderUnitId;
        $this->resetValidation();
    }
}; ?>

<div>
    <x-header title="ثبت کیس بیماری" separator/>

    {{-- Step 1 — patient by national code ───────────────────────────────── --}}
    <x-card shadow>
        <h2 class="font-bold mb-3">۱. بیمار</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <x-input
                    label="کد ملی"
                    wire:model.live.debounce.500ms="kodMelli"
                    dir="ltr"
                    icon="o-identification"
                    placeholder="۱۰ رقم"
                />
                @error('kodMelli')
                    <p class="text-xs text-error mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

        @if($patientPreview)
            <div class="mt-3 rounded-lg border border-success/40 bg-success/5 p-3 text-sm" wire:key="patient-preview-{{ $patientPreview['id'] }}">
                <span class="font-medium">{{ $patientPreview['first_name'] }} {{ $patientPreview['last_name'] }}</span>
                <span class="font-mono text-xs opacity-60 ms-2" dir="ltr">{{ $patientPreview['kod_melli'] }}</span>
            </div>
        @endif

        @if($inlinePatient)
            <div class="mt-4 rounded-lg border border-warning/40 bg-warning/5 p-3">
                <p class="text-sm font-medium">بیمار با این کد ملی یافت نشد — ثبت بیمار جدید</p>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mt-3">
                    <div>
                        <x-input label="کد ملی" wire:model="newPatient.kod_melli" dir="ltr" placeholder="۱۰ رقم" icon="o-identification"/>
                        @error('newPatient.kod_melli')
                            <p class="text-xs text-error mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-input label="نام" wire:model="newPatient.first_name" placeholder="نام"/>
                    <x-input label="نام خانوادگی" wire:model="newPatient.last_name" placeholder="نام خانوادگی"/>

                    <div class="form-control">
                        <label class="label" for="disease_patient_birth_date">
                            <span class="label-text">تاریخ تولد (جلالی)</span>
                        </label>
                        <input
                            id="disease_patient_birth_date"
                            data-jdp
                            data-jdp-model="newPatient.birth_date_jalali"
                            wire:ignore
                            readonly
                            dir="ltr"
                            class="input input-bordered w-full"
                            placeholder="YYYY/MM/DD"
                        >
                        @error('newPatient.birth_date_jalali')
                            <p class="text-xs text-error mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-select
                        label="جنسیت"
                        wire:model="newPatient.gender"
                        :options="$this->genderOptions()"
                        option-value="value"
                        option-label="label"
                        placeholder="انتخاب کنید"
                    />
                </div>

                <x-button
                    label="ثبت بیمار"
                    icon="o-user-plus"
                    wire:click="createPatient"
                    class="btn-primary btn-sm mt-3"
                    spinner
                />
            </div>
        @endif
    </x-card>

    {{-- Step 2 — active disease ──────────────────────────────────────────── --}}
    <x-card shadow class="mt-4">
        <h2 class="font-bold mb-3">۲. بیماری</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <x-select
                    label="بیماری"
                    wire:model.live="diseaseId"
                    :options="$this->diseaseOptions()"
                    option-value="value"
                    option-label="label"
                    placeholder="انتخاب بیماری"
                />
                @error('diseaseId')
                    <p class="text-xs text-error mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </x-card>

    {{-- Step 3 — dynamic fields ──────────────────────────────────────────── --}}
    @if($diseaseId)
        <x-card shadow class="mt-4">
            <h2 class="font-bold mb-3">۳. فیلدهای بیماری</h2>

            @if($defs->isEmpty())
                <p class="text-sm text-base-content/60">برای این بیماری فیلدی تعریف نشده است.</p>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @include('livewire.disease._case-fields', ['defs' => $defs, 'selectOptions' => $selectOptions])
            </div>
        </x-card>
    @endif

    {{-- Step 4 — the two dates ───────────────────────────────────────────── --}}
    <x-card shadow class="mt-4">
        <h2 class="font-bold mb-3">۴. تاریخ‌ها</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="form-control">
                <label class="label" for="disease_diagnosed_at">
                    <span class="label-text">تاریخ تشخیص (جلالی)</span>
                </label>
                <input
                    id="disease_diagnosed_at"
                    data-jdp
                    data-jdp-model="diagnosedAt"
                    wire:ignore
                    readonly
                    dir="ltr"
                    class="input input-bordered w-full"
                    placeholder="YYYY/MM/DD"
                >
                @error('diagnosedAt')
                    <p class="text-xs text-error mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-control">
                <label class="label" for="disease_recorded_at">
                    <span class="label-text">تاریخ ثبت (جلالی)</span>
                </label>
                <input
                    id="disease_recorded_at"
                    data-jdp
                    data-jdp-model="recordedAt"
                    wire:ignore
                    readonly
                    dir="ltr"
                    class="input input-bordered w-full"
                    placeholder="YYYY/MM/DD"
                >
                @error('recordedAt')
                    <p class="text-xs text-error mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </x-card>

    {{-- Step 5 — subject unit ────────────────────────────────────────────── --}}
    <x-card shadow class="mt-4">
        <h2 class="font-bold mb-3">۵. واحد بیمار</h2>

        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm">
                {{ $this->selectedUnitName ?? 'واحدی انتخاب نشده است' }}
            </span>

            <x-button
                label="انتخاب واحد"
                icon="o-building-office-2"
                wire:click="$set('unitModal', true)"
                class="btn-outline btn-sm"
            />
        </div>
        @error('subjectUnitId')
            <p class="text-xs text-error mt-1">{{ $message }}</p>
        @enderror
    </x-card>

    {{-- Step 6 — submit ──────────────────────────────────────────────────── --}}
    <div class="mt-4">
        <x-button
            label="ثبت کیس"
            icon="o-check"
            wire:click="save"
            class="btn-primary"
            spinner
        />
    </div>

    <x-modal wire:model="unitModal" title="انتخاب واحد بیمار" persistent separator>
        {{-- Render-on-open (#1171): the picker builds the whole tree in PHP and
             must never render while the modal is closed. --}}
        @if($unitModal)
            @include('livewire.partials.unit-tree-picker', [
                'units' => $this->unitTree(),
                'model' => 'subjectUnitId',
                'multiple' => false,
                'alwaysOpen' => true,
                'label' => 'واحد بیمار',
            ])
        @endif

        <x-slot:actions>
            <x-button label="تأیید" icon="o-check" class="btn-primary" wire:click="$set('unitModal', false)"/>
        </x-slot:actions>
    </x-modal>
</div>

@assets
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
@endassets

@script
<script>
    // Issue #1182: page-scoped Jalali datepicker. Every date input carries
    // `data-jdp-model` naming the Livewire property it belongs to, so the same
    // binding works for the two fixed dates, the patient birth date and any
    // dynamic date field added by the disease definitions.
    const initDiseaseCaseJdp = () => {
        if (typeof jalaliDatepicker === 'undefined') {
            return;
        }

        jalaliDatepicker.startWatch({
            time: false,
            hasSecond: false,
            format: 'YYYY/MM/DD',
            separatorChars: { date: '/', between: ' ', time: ':' },
        });

        document.querySelectorAll('[data-jdp][data-jdp-model]').forEach(input => {
            if (!input.dataset.jdpBound) {
                input.dataset.jdpBound = '1';
                input.addEventListener('jdp:change', event => {
                    $wire.set(input.dataset.jdpModel, event.target.value);
                });
            }

            // `wire:ignore` keeps the DOM value out of Livewire's hands, so the
            // server state is mirrored back after every round-trip.
            const value = $wire.get(input.dataset.jdpModel);
            if (value !== undefined && value !== null) {
                input.value = value;
            }
        });
    };

    initDiseaseCaseJdp();
    document.addEventListener('livewire:navigated', initDiseaseCaseJdp);
    Livewire.hook('morphed', () => initDiseaseCaseJdp());
</script>
@endscript
