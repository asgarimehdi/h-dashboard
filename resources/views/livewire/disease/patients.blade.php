<?php

use App\Models\DiseaseCase;
use App\Models\Patient;
use App\Rules\IranianNationalCode;
use App\Services\AccessService;
use App\Services\PatientService;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public string $searchCode = '';

    public string $searchName = '';

    public ?int $selectedPatientId = null;

    public ?int $duplicatePatientId = null;

    public bool $formOpen = false;

    public string $kod_melli = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $birth_date_jalali = '';

    public string $gender = '';

    public function mount(): void
    {
        $this->authorize('manage_diseases');
    }

    public function updatedSearchCode(): void
    {
        // POST /livewire/update does not re-run route middleware (#897), so
        // every public entry point authorizes on its own.
        $this->authorize('manage_diseases');
        $this->resetPage();
    }

    public function updatedSearchName(): void
    {
        $this->authorize('manage_diseases');
        $this->resetPage();
    }

    public function selectPatient(int $id): void
    {
        // #897: POST /livewire/update skips route middleware, so every public
        // entry point authorizes. `int $id` + findOrFail — a typed Patient
        // parameter would bind by route key (`n_code`), not by `id`.
        $this->authorize('manage_diseases');

        $patient = Patient::query()->findOrFail($id);

        $this->selectedPatientId = $patient->id;
    }

    public function closePatient(): void
    {
        $this->authorize('manage_diseases');

        $this->selectedPatientId = null;
        $this->duplicatePatientId = null;
    }

    public function selectedPatient(): ?Patient
    {
        $this->authorize('manage_diseases');

        if ($this->selectedPatientId === null) {
            return null;
        }

        return Patient::query()->find($this->selectedPatientId);
    }

    /**
     * The patient's cases, restricted to the actor's unit scope. `visibleTo([])`
     * compiles to `0 = 1` (fail-closed), never "no filter".
     *
     * @return Collection<int, DiseaseCase>
     */
    public function patientCases(): Collection
    {
        $this->authorize('manage_diseases');

        if ($this->selectedPatientId === null) {
            return new Collection;
        }

        return DiseaseCase::visibleTo(app(AccessService::class)->accessibleUnitIds())
            ->with(['disease.fieldDefs', 'subjectUnit'])
            ->where('patient_id', $this->selectedPatientId)
            ->orderByDesc('diagnosed_at')
            ->orderByDesc('id')
            ->get();
    }

    public function openCreateForm(): void
    {
        $this->authorize('manage_diseases');

        $this->resetValidation();
        $this->reset(['first_name', 'last_name', 'birth_date_jalali', 'gender']);
        $this->duplicatePatientId = null;
        // Inline-create: the searched code is the one being registered.
        $this->kod_melli = $this->normalizedCode();
        $this->formOpen = true;
    }

    public function savePatient(): void
    {
        $this->authorize('manage_diseases');

        // Normalize first: `digits:10` must run against Latin digits.
        $this->kod_melli = trim(PersianNormalizer::normalizeForSearch($this->kod_melli));

        $this->validate(PatientService::validationRules());

        $service = app(PatientService::class);

        // Friendly uniqueness: never a 500. The code is the registry key.
        $existing = $service->findByKodMelli($this->kod_melli);

        if ($existing !== null) {
            $this->addError('kod_melli', PatientService::duplicateMessage($existing));
            $this->duplicatePatientId = $existing->id;

            return;
        }

        try {
            $patient = $service->create([
                'kod_melli' => $this->kod_melli,
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'birth_date_jalali' => $this->birth_date_jalali,
                'gender' => $this->gender,
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (array_key_exists('duplicate', $errors)) {
                // Race: another request inserted the same code between the lookup
                // and the insert. Same friendly path, same existing row.
                $existing = $service->findByKodMelli($this->kod_melli);

                $this->addError(
                    'kod_melli',
                    $existing !== null
                        ? PatientService::duplicateMessage($existing)
                        : (string) ($errors['duplicate'][0] ?? 'این کد ملی قبلاً ثبت شده است.'),
                );
                $this->duplicatePatientId = $existing?->id;

                return;
            }

            // Service-side rule violations — same shared rules, re-run over the
            // normalized copy inside create() (#1300); flat keys match this form.
            foreach ($errors as $key => $keyMessages) {
                $this->addError((string) $key, array_first($keyMessages) ?? '');
            }

            return;
        }

        $this->resetValidation();
        $this->reset(['kod_melli', 'first_name', 'last_name', 'birth_date_jalali', 'gender']);
        $this->duplicatePatientId = null;
        $this->formOpen = false;
        $this->searchCode = '';
        $this->searchName = '';
        $this->resetPage();
        $this->selectedPatientId = $patient->id;
        $this->success('بیمار با موفقیت ثبت شد.');
    }

    public function openDuplicatePatient(): void
    {
        $this->authorize('manage_diseases');

        if ($this->duplicatePatientId === null) {
            return;
        }

        $id = $this->duplicatePatientId;
        $this->formOpen = false;
        $this->duplicatePatientId = null;
        // selectPatient re-authorizes and re-reads by id (client-controlled).
        $this->selectPatient($id);
    }

    /**
     * MaryUI `option-value` / `option-label` are mandatory with value/label
     * keys (#706) — otherwise every `<option>` renders empty.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function genderOptions(): array
    {
        return [
            ['value' => 'male', 'label' => 'مرد'],
            ['value' => 'female', 'label' => 'زن'],
        ];
    }

    public function hasSearch(): bool
    {
        return $this->normalizedCode() !== '' || trim($this->searchName) !== '';
    }

    public function codeLengthInvalid(): bool
    {
        $code = $this->normalizedCode();

        return $code !== '' && strlen($code) !== 10;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'full_name', 'label' => 'نام و نام خانوادگی'],
            ['key' => 'kod_melli', 'label' => 'کد ملی'],
            ['key' => 'birth_date', 'label' => 'تاریخ تولد'],
            ['key' => 'gender', 'label' => 'جنسیت'],
        ];
    }

    /**
     * Two independent axes, combined with AND: an exact national code and a
     * multi-term name search. No term at all means no rows (PII minimization).
     *
     * @return LengthAwarePaginator<int, Patient>
     */
    public function searchPatients(): LengthAwarePaginator
    {
        $this->authorize('manage_diseases');

        $code = $this->normalizedCode();
        $name = trim($this->searchName);

        if ($code === '' && $name === '') {
            return $this->emptyPage();
        }

        // A national code is exactly ten digits; a shorter/longer value is a
        // half-typed query, not a validation error (step 4 contract).
        if ($code !== '' && strlen($code) !== 10) {
            return $this->emptyPage();
        }

        $query = Patient::query();

        if ($code !== '') {
            // Exact equality, never LIKE: the code is the registry key.
            $query->where('kod_melli', $code);
        }

        if ($name !== '') {
            // #940: normalize the list ONCE, fold each term afterwards. Folding
            // an already-escaped term would escape LIKE wildcards twice.
            $terms = array_values(array_filter(
                explode(' ', PersianNormalizer::normalizeForSearch($name)),
                static fn (string $term): bool => $term !== '',
            ));

            foreach ($terms as $term) {
                $folded = PersianNormalizer::foldedTerm($term);

                $query->where(function (Builder $termQuery) use ($folded): void {
                    // The column is folded too: `patients` has no save hook, so
                    // a stored «آ»/ZWNJ must still match the folded term.
                    $termQuery
                        ->whereRaw(PersianNormalizer::foldSeparatorsSql('first_name').' LIKE ?', ["%{$folded}%"])
                        ->orWhereRaw(PersianNormalizer::foldSeparatorsSql('last_name').' LIKE ?', ["%{$folded}%"])
                        ->orWhereRaw(PersianNormalizer::foldSeparatorsSql("CONCAT(first_name, ' ', last_name)").' LIKE ?', ["%{$folded}%"])
                        ->orWhereRaw(PersianNormalizer::foldSeparatorsSql("CONCAT(last_name, ' ', first_name)").' LIKE ?', ["%{$folded}%"]);
                });
            }
        }

        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $patients = $this->searchPatients();

        return [
            'patients' => $patients,
            'headers' => $this->headers(),
            'showCreateCta' => $this->codeIsCreateCandidate() && $patients->total() === 0,
        ];
    }

    private function normalizedCode(): string
    {
        return trim(PersianNormalizer::normalizeForSearch($this->searchCode));
    }

    /**
     * Only a checksum-valid code is offered as an inline-create candidate;
     * offering it for a malformed code would hand the form a doomed value.
     */
    private function codeIsCreateCandidate(): bool
    {
        $code = $this->normalizedCode();

        return strlen($code) === 10 && IranianNationalCode::isValid($code);
    }

    /**
     * @return LengthAwarePaginator<int, Patient>
     */
    private function emptyPage(): LengthAwarePaginator
    {
        return Patient::query()->whereRaw('1 = 0')->paginate(20);
    }
}; ?>

<div>
    <x-header title="پروندهٔ بیماران" separator progress-indicator/>

    <x-card shadow>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <x-input
                label="کد ملی (دقیق)"
                wire:model.live.debounce.500ms="searchCode"
                clearable
                icon="o-identification"
                dir="ltr"
                placeholder="۱۰ رقم"
            />

            <x-input
                label="نام / نام خانوادگی"
                wire:model.live.debounce.500ms="searchName"
                clearable
                icon="o-magnifying-glass"
                placeholder="نام یا نام خانوادگی"
            />
        </div>

        @if($this->codeLengthInvalid())
            <p class="text-xs text-warning mt-2">کد ملی ۱۰ رقم است.</p>
        @endif
    </x-card>

    <x-card shadow class="mt-4">
        @if(! $this->hasSearch())
            <p class="text-sm text-base-content/60">
                برای دیدن پرونده، کد ملی یا نام بیمار را جستجو کنید.
            </p>
        @elseif($patients->isEmpty())
            <p class="text-sm text-base-content/60">بیماری با این مشخصات یافت نشد.</p>

            @if($showCreateCta)
                <x-button
                    label="بیمار با این کد یافت نشد — ثبت بیمار جدید"
                    wire:click="openCreateForm"
                    icon="o-plus"
                    class="btn-primary mt-3"
                />
            @endif
        @else
            <x-table :headers="$headers" :rows="$patients" with-pagination>
                @scope('cell_full_name', $patient)
                    <button
                        type="button"
                        wire:key="patient-row-{{ $patient->id }}"
                        wire:click="selectPatient({{ $patient->id }})"
                        class="font-medium link link-hover text-right"
                    >
                        {{ $patient->fullName() }}
                    </button>
                @endscope

                @scope('cell_kod_melli', $patient)
                    <span dir="ltr" class="font-mono text-xs">{{ $patient->kod_melli }}</span>
                @endscope

                @scope('cell_birth_date', $patient)
                    {{ $patient->birth_date ? \Morilog\Jalali\Jalalian::fromCarbon($patient->birth_date)->format('Y/m/d') : '—' }}
                @endscope

                @scope('cell_gender', $patient)
                    {{ $patient->genderLabel() }}
                @endscope
            </x-table>
        @endif
    </x-card>

    @php($selectedPatient = $this->selectedPatient())

    @if($selectedPatient)
        <x-card shadow class="mt-4" wire:key="patient-panel-{{ $selectedPatient->id }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold">{{ $selectedPatient->fullName() }}</h2>
                    <p class="text-xs font-mono opacity-60" dir="ltr">{{ $selectedPatient->kod_melli }}</p>
                </div>

                <x-button
                    label="بستن"
                    icon="o-x-mark"
                    wire:click="closePatient"
                    class="btn-ghost btn-sm"
                />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 text-sm">
                <div><span class="opacity-60">نام:</span> {{ $selectedPatient->first_name }}</div>
                <div><span class="opacity-60">نام خانوادگی:</span> {{ $selectedPatient->last_name }}</div>
                <div>
                    <span class="opacity-60">تاریخ تولد:</span>
                    {{ $selectedPatient->birth_date ? \Morilog\Jalali\Jalalian::fromCarbon($selectedPatient->birth_date)->format('Y/m/d') : '—' }}
                </div>
                <div><span class="opacity-60">جنسیت:</span> {{ $selectedPatient->genderLabel() }}</div>
            </div>

            <h3 class="text-sm font-bold mt-6 mb-2">پرونده‌های بیماری</h3>

            @forelse($this->patientCases() as $case)
                <div class="border border-base-300 rounded-lg p-3 mt-2" wire:key="patient-case-{{ $case->id }}">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="font-medium">{{ $case->disease->name_fa }}</span>
                        <span class="text-xs opacity-60">
                            تشخیص: {{ \Morilog\Jalali\Jalalian::fromCarbon($case->diagnosed_at)->format('Y/m/d') }}
                        </span>
                        <span class="text-xs opacity-60">
                            ثبت: {{ \Morilog\Jalali\Jalalian::fromCarbon($case->recorded_at)->format('Y/m/d') }}
                        </span>
                        <span class="text-xs opacity-60">{{ $case->subjectUnit->name }}</span>
                    </div>

                    @php($fieldDefs = $case->disease->fieldDefs->sortBy('sort'))
                    @php($definedKeys = $fieldDefs->pluck('field_key')->all())
                    @php($orphanKeys = array_diff(array_keys($case->fields ?? []), $definedKeys))

                    @foreach($fieldDefs as $def)
                        @if(array_key_exists($def->field_key, $case->fields ?? []))
                            <div class="flex gap-1 text-sm mt-1">
                                <span class="opacity-60">{{ $def->label_fa }}:</span>
                                <span>{{ $case->fields[$def->field_key] }}</span>
                                @if($def->unit)<span class="text-xs opacity-60">{{ $def->unit }}</span>@endif
                            </div>
                        @endif
                    @endforeach

                    {{-- A stored key without a definition still renders (raw key),
                         so data is never silently hidden. --}}
                    @foreach($orphanKeys as $orphanKey)
                        <div class="flex gap-1 text-sm mt-1">
                            <span class="opacity-60">{{ $orphanKey }}:</span>
                            <span>{{ $case->fields[$orphanKey] }}</span>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="text-sm text-base-content/60">موردی در دسترس شما نیست</p>
            @endforelse
        </x-card>
    @endif

    <x-modal wire:model="formOpen" title="ثبت بیمار جدید" separator close-on-backdrop>
        @include('livewire.disease.partials.patient-form', ['genderOptions' => $this->genderOptions()])

        @if($duplicatePatientId)
            <div class="mt-3">
                <x-button
                    label="مشاهدهٔ پرونده"
                    icon="o-arrow-left"
                    wire:click="openDuplicatePatient"
                    class="btn-outline btn-sm"
                />
            </div>
        @endif

        <x-slot:actions>
            <x-button label="انصراف" wire:click="$set('formOpen', false)" />
            <x-button label="ثبت" icon="o-check" wire:click="savePatient" class="btn-primary" spinner />
        </x-slot:actions>
    </x-modal>
</div>

@assets
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
@endassets

@script
<script>
    // Issue #1182: page-scoped asset, not the global layout. `wire:ignore` means
    // the picker owns the input value, so every server-side change is mirrored
    // back into the DOM.
    const initPatientJdp = () => {
        if (typeof jalaliDatepicker === 'undefined') {
            return;
        }

        jalaliDatepicker.startWatch({
            time: false,
            hasSecond: false,
            format: 'YYYY/MM/DD',
            separatorChars: { date: '/', between: ' ', time: ':' },
        });

        const input = document.getElementById('patient_birth_date');

        if (!input) {
            return;
        }

        if (!input.dataset.jdpBound) {
            input.dataset.jdpBound = '1';
            input.addEventListener('jdp:change', event => {
                $wire.set('birth_date_jalali', event.target.value);
            });
        }

        input.value = $wire.get('birth_date_jalali') ?? '';
    };

    initPatientJdp();
    document.addEventListener('livewire:navigated', initPatientJdp);
    $wire.$watch('formOpen', initPatientJdp);
    $wire.$watch('birth_date_jalali', value => {
        const input = document.getElementById('patient_birth_date');
        if (input) {
            input.value = value ?? '';
        }
    });
</script>
@endscript
