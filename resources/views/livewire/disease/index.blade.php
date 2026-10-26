<?php

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Unit;
use App\Rules\AccessibleUnit;
use App\Services\AccessService;
use App\Services\Disease\DiseaseCaseService;
use App\Support\JalaliDateRange;
use App\Traits\BuildsDiseaseCaseForm;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use BuildsDiseaseCaseForm;
    use Toast;
    use WithPagination;

    // ── filters ──
    public string $search = '';

    public ?int $diseaseFilter = null;

    /** Jalali `Y/m/d`; empty means "unbounded" (decision ح: default = all). */
    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $subjectUnitFilter = null;

    public int $perPage = 20;

    public bool $emptyScope = false;

    // ── edit form (patient and disease stay read-only — decision د) ──
    public ?int $editingId = null;

    public ?int $editingDiseaseId = null;

    public string $editingPatientName = '';

    public string $editingDiseaseName = '';

    public string $diagnosedAt = '';

    public string $recordedAt = '';

    public ?int $subjectUnitId = null;

    public bool $unitModal = false;

    /**
     * Stored keys with no active definition (#1242 may hide/delete one). They
     * are read-only history: never rendered as inputs, never destroyed (§8.4).
     *
     * @var array<string, mixed>
     */
    public array $orphanFields = [];

    public function mount(): void
    {
        $this->authorize('manage_diseases');

        $this->emptyScope = app(AccessService::class)->accessibleUnitIds() === [];
    }

    /**
     * A filter change starts a new result set (issue #1024's contract). The
     * Jalali pickers write through `$wire.set`, which lands here too.
     */
    public function updated(string $property): void
    {
        // POST /livewire/update does not re-run route middleware (#897).
        $this->authorize('manage_diseases');

        if (in_array($property, ['search', 'diseaseFilter', 'dateFrom', 'dateTo', 'subjectUnitFilter', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    /**
     * The list. `visibleTo([])` compiles to `0 = 1` (fail-closed) — never
     * "no filter".
     *
     * @return LengthAwarePaginator<int, DiseaseCase>
     */
    public function cases(): LengthAwarePaginator
    {
        $this->authorize('manage_diseases');

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = DiseaseCase::visibleTo($accessibleIds)
            ->with([
                'patient:id,kod_melli,first_name,last_name',
                'disease:id,name_fa',
                'subjectUnit:id,name',
                'unit:id,name',
            ]);

        if ($this->diseaseFilter !== null && $this->diseaseFilter > 0) {
            $query->where('disease_id', $this->diseaseFilter);
        }

        $range = JalaliDateRange::range($this->dateFrom, $this->dateTo);

        if ($range !== null) {
            $query->whereBetween('diagnosed_at', [$range[0]->toDateString(), $range[1]->toDateString()]);
        }

        if ($this->subjectUnitFilter !== null) {
            $subjectUnitId = (int) $this->subjectUnitFilter;

            // A client-supplied id outside the scope is ignored, never trusted
            // (the rows are already scoped, this keeps the predicate honest).
            if (in_array($subjectUnitId, array_map(intval(...), $accessibleIds), true)) {
                $query->where('subject_unit_id', $subjectUnitId);
            }
        }

        $this->applyPatientSearch($query);

        return $query
            ->orderByDesc('diagnosed_at')
            ->orderByDesc('id')
            ->paginate($this->pageSize());
    }

    /**
     * Patient name or national code. The code is exact; a name is a multi-term
     * folded search where BOTH the column and each term are folded — folding
     * only the term is #940.
     *
     * @param  Builder<DiseaseCase>  $query
     */
    private function applyPatientSearch(Builder $query): void
    {
        $search = trim(PersianNormalizer::normalizeForSearch($this->search));

        if ($search === '') {
            return;
        }

        if (preg_match('/^\d{10}$/', $search) === 1) {
            $query->whereHas('patient', static function (Builder $patientQuery) use ($search): void {
                $patientQuery->where('kod_melli', $search);
            });

            return;
        }

        $terms = array_values(array_filter(
            explode(' ', $search),
            static fn (string $term): bool => $term !== '',
        ));

        foreach ($terms as $term) {
            $folded = PersianNormalizer::foldedTerm($term);

            $query->whereHas('patient', static function (Builder $patientQuery) use ($folded): void {
                $patientQuery->where(function (Builder $termQuery) use ($folded): void {
                    $termQuery
                        ->whereRaw(PersianNormalizer::foldSeparatorsSql('first_name').' LIKE ?', ["%{$folded}%"])
                        ->orWhereRaw(PersianNormalizer::foldSeparatorsSql('last_name').' LIKE ?', ["%{$folded}%"])
                        ->orWhereRaw(PersianNormalizer::foldSeparatorsSql("CONCAT(first_name, ' ', last_name)").' LIKE ?', ["%{$folded}%"])
                        ->orWhereRaw(PersianNormalizer::foldSeparatorsSql("CONCAT(last_name, ' ', first_name)").' LIKE ?', ["%{$folded}%"]);
                });
            });
        }
    }

    /**
     * Open the edit form. A case outside the scope is not "not found" to the
     * user — it is forbidden.
     */
    public function edit(int $id): void
    {
        $this->authorize('manage_diseases');

        try {
            $case = DiseaseCase::visibleTo(app(AccessService::class)->accessibleUnitIds())
                ->with(['patient:id,kod_melli,first_name,last_name', 'disease:id,name_fa'])
                ->findOrFail($id);
        } catch (ModelNotFoundException) {
            abort(403);
        }

        $this->editingId = $case->id;
        $this->editingDiseaseId = $case->disease_id;
        $this->editingPatientName = $case->patient->fullName();
        $this->editingDiseaseName = $case->disease->name_fa;
        $this->diagnosedAt = Jalalian::fromCarbon($case->diagnosed_at)->format('Y/m/d');
        $this->recordedAt = Jalalian::fromCarbon($case->recorded_at)->format('Y/m/d');
        $this->subjectUnitId = $case->subject_unit_id;

        $stored = $case->fields ?? [];
        $this->fields = $this->fieldsFromStored($stored, $case->disease_id);
        $this->orphanFields = array_diff_key($stored, $this->emptyFieldsFor($case->disease_id));

        $this->resetValidation();
    }

    /**
     * The single write path for an edit: patient/disease come from the stored
     * row (never the payload), `unit_id` is the original recorder and is never
     * touched, and the service remains the §3.4 authority.
     */
    public function saveEdit(): void
    {
        $this->authorize('manage_diseases');

        if ($this->editingId === null) {
            return;
        }

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        try {
            $case = DiseaseCase::visibleTo($accessibleIds)->findOrFail($this->editingId);
        } catch (ModelNotFoundException) {
            abort(403);
        }

        $this->fields = $this->nonEmptyFields($this->fields);

        $this->validate([
            'diagnosedAt' => ['required', 'string', $this->jalaliDateRule()],
            'recordedAt' => ['required', 'string', $this->jalaliDateRule()],
            'subjectUnitId' => ['required', 'integer', new AccessibleUnit],
        ] + $this->fieldRules($case->disease_id));

        $diagnosedAt = JalaliDateRange::parse($this->diagnosedAt);
        $recordedAt = JalaliDateRange::parse($this->recordedAt);

        if ($diagnosedAt === null || $recordedAt === null) {
            // Unreachable after the rules above; keeps the types honest.
            $this->addError('diagnosedAt', 'تاریخ واردشده معتبر نیست.');

            return;
        }

        $diagnosedOn = $diagnosedAt->toDateString();

        $duplicate = DiseaseCase::query()
            ->whereKeyNot($case->id)
            ->where('patient_id', $case->patient_id)
            ->where('disease_id', $case->disease_id)
            ->whereDate('diagnosed_at', $diagnosedOn)
            ->exists();

        if ($duplicate) {
            $this->duplicateWarning();

            return;
        }

        try {
            $updated = app(DiseaseCaseService::class)->update($case->id, [
                'patient_id' => $case->patient_id,
                'disease_id' => $case->disease_id,
                'subject_unit_id' => (int) $this->subjectUnitId,
                'diagnosed_at' => $diagnosedOn,
                'recorded_at' => $recordedAt->toDateString(),
                'fields' => $this->fieldsForService($this->fields, $case->disease_id),
            ], $accessibleIds);
        } catch (ValidationException $e) {
            if (array_key_exists('duplicate', $e->errors())) {
                $this->duplicateWarning();

                return;
            }

            $this->mapServiceValidationErrors($e);

            return;
        }

        // The validator only accepts known keys, so orphaned historical keys
        // are merged back outside the write path — they are not new input.
        if ($this->orphanFields !== []) {
            $updated->forceFill(['fields' => $this->orphanFields + ($updated->fields ?? [])])->saveQuietly();
        }

        $this->success('کیس بیماری ویرایش شد.', position: 'toast-bottom');
        $this->closeEdit();
        $this->resetPage();
    }

    public function closeEdit(): void
    {
        $this->authorize('manage_diseases');

        $this->reset([
            'editingId',
            'editingDiseaseId',
            'editingPatientName',
            'editingDiseaseName',
            'diagnosedAt',
            'recordedAt',
            'subjectUnitId',
            'fields',
            'orphanFields',
            'unitModal',
        ]);

        $this->resetValidation();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage_diseases');

        try {
            app(DiseaseCaseService::class)->delete($id, app(AccessService::class)->accessibleUnitIds());
        } catch (ModelNotFoundException) {
            abort(403);
        }

        if ($this->editingId === $id) {
            $this->closeEdit();
        }

        $this->resetPage();
        $this->success('کیس بیماری حذف شد.', position: 'toast-bottom');
    }

    /**
     * Filter options. Deliberately every disease, active or not: the list is
     * historical and a retired disease still owns its old cases.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function diseaseOptions(): array
    {
        return Disease::query()
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
     * The subject-unit filter only offers units the actor can already see.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function unitOptions(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        return Unit::query()
            ->whereIn('id', $accessibleIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (mixed $unit): array => [
                'value' => (int) data_get($unit, 'id'),
                'label' => (string) data_get($unit, 'name'),
            ])
            ->all();
    }

    /**
     * Render-on-open picker rows (same contract as `disease.create`).
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
     * @return array<int, array{key: string, label: string}>
     */
    public function headers(): array
    {
        return [
            ['key' => 'patient', 'label' => 'بیمار'],
            ['key' => 'disease', 'label' => 'بیماری'],
            ['key' => 'diagnosed_at', 'label' => 'تاریخ تشخیص'],
            ['key' => 'recorded_at', 'label' => 'تاریخ ثبت'],
            ['key' => 'subject_unit', 'label' => 'واحد بیمار'],
            ['key' => 'actions', 'label' => ''],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'cases' => $this->cases(),
            'headers' => $this->headers(),
            'defs' => $this->fieldDefsFor($this->editingDiseaseId),
            'selectOptions' => $this->selectOptionsMap($this->editingDiseaseId),
        ];
    }

    private function pageSize(): int
    {
        // Defensive clamp: `paginate(0)` divides by zero.
        return max(1, min(100, $this->perPage));
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
}; ?>

<div>
    <x-header title="کیس‌های بیماری" separator progress-indicator/>

    @if($emptyScope)
        <div class="alert alert-info mb-6" role="alert">
            <x-icon name="o-information-circle" class="w-5 h-5"/>
            <span>واحدی برای نمایش انتخاب نشده</span>
        </div>
    @endif

    {{-- Edit — the shared dynamic form, patient/disease read-only ─────────── --}}
    @if($editingId)
        <x-card shadow class="mb-4" wire:key="disease-case-edit-{{ $editingId }}">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <h2 class="font-bold">ویرایش کیس</h2>
                    <p class="text-sm mt-1">
                        <span class="font-medium">{{ $editingPatientName }}</span>
                        <span class="opacity-60 mx-2">·</span>
                        <span>{{ $editingDiseaseName }}</span>
                    </p>
                    <p class="text-xs text-base-content/60 mt-1">
                        بیمار و بیماری در ویرایش قابل تغییر نیستند؛ برای تغییر، کیس را حذف و دوباره ثبت کنید.
                    </p>
                </div>

                <x-button label="بستن" icon="o-x-mark" wire:click="closeEdit" class="btn-ghost btn-sm"/>
            </div>

            @if($defs->isEmpty())
                <p class="text-sm text-base-content/60">برای این بیماری فیلدی تعریف نشده است.</p>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @include('livewire.disease._case-fields', ['defs' => $defs, 'selectOptions' => $selectOptions])
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4">
                <div class="form-control">
                    <label class="label" for="disease_case_edit_diagnosed_at">
                        <span class="label-text">تاریخ تشخیص (جلالی)</span>
                    </label>
                    <input
                        id="disease_case_edit_diagnosed_at"
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
                    <label class="label" for="disease_case_edit_recorded_at">
                        <span class="label-text">تاریخ ثبت (جلالی)</span>
                    </label>
                    <input
                        id="disease_case_edit_recorded_at"
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

            <div class="flex flex-wrap items-center gap-3 mt-4">
                <span class="text-sm">
                    واحد بیمار: {{ $this->selectedUnitName ?? 'انتخاب نشده' }}
                </span>

                <x-button
                    label="تغییر واحد"
                    icon="o-building-office-2"
                    wire:click="$set('unitModal', true)"
                    class="btn-outline btn-sm"
                />
            </div>
            @error('subjectUnitId')
                <p class="text-xs text-error mt-1">{{ $message }}</p>
            @enderror

            <div class="flex gap-2 mt-4">
                <x-button label="ذخیرهٔ تغییرات" icon="o-check" wire:click="saveEdit" class="btn-primary" spinner/>
                <x-button label="انصراف" wire:click="closeEdit" class="btn-ghost"/>
            </div>
        </x-card>
    @endif

    {{-- Filters ─────────────────────────────────────────────────────────── --}}
    <x-card shadow>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
            <x-input
                label="بیمار (نام یا کد ملی)"
                wire:model.live.debounce.500ms="search"
                clearable
                icon="o-magnifying-glass"
                placeholder="نام، نام خانوادگی یا ۱۰ رقم"
            />

            <x-select
                label="بیماری"
                wire:model.live="diseaseFilter"
                :options="$this->diseaseOptions()"
                option-value="value"
                option-label="label"
                placeholder="همهٔ بیماری‌ها"
                clearable
            />

            <x-select
                label="واحد بیمار"
                wire:model.live="subjectUnitFilter"
                :options="$this->unitOptions()"
                option-value="value"
                option-label="label"
                placeholder="همهٔ واحدها"
                clearable
            />

            <div class="form-control">
                <label class="label" for="disease_case_filter_from">
                    <span class="label-text">از تاریخ تشخیص</span>
                </label>
                <input
                    id="disease_case_filter_from"
                    data-jdp
                    data-jdp-model="dateFrom"
                    wire:ignore
                    readonly
                    dir="ltr"
                    class="input input-bordered w-full"
                    placeholder="YYYY/MM/DD"
                >
            </div>

            <div class="form-control">
                <label class="label" for="disease_case_filter_to">
                    <span class="label-text">تا تاریخ تشخیص</span>
                </label>
                <input
                    id="disease_case_filter_to"
                    data-jdp
                    data-jdp-model="dateTo"
                    wire:ignore
                    readonly
                    dir="ltr"
                    class="input input-bordered w-full"
                    placeholder="YYYY/MM/DD"
                >
            </div>
        </div>
    </x-card>

    {{-- List ─────────────────────────────────────────────────────────────── --}}
    <x-card shadow class="mt-4">
        @if($cases->isEmpty())
            <p class="text-sm text-base-content/60">کیسی با این مشخصات یافت نشد.</p>
        @else
            <x-table :headers="$headers" :rows="$cases" with-pagination>
                @scope('cell_patient', $case)
                    <div wire:key="case-patient-{{ $case->id }}">
                        <span class="font-medium">{{ $case->patient->fullName() }}</span>
                        <span class="font-mono text-xs opacity-60 ms-2" dir="ltr">{{ $case->patient->kod_melli }}</span>
                    </div>
                @endscope

                @scope('cell_disease', $case)
                    {{ $case->disease->name_fa }}
                @endscope

                @scope('cell_diagnosed_at', $case)
                    {{ \Morilog\Jalali\Jalalian::fromCarbon($case->diagnosed_at)->format('Y/m/d') }}
                @endscope

                @scope('cell_recorded_at', $case)
                    {{ \Morilog\Jalali\Jalalian::fromCarbon($case->recorded_at)->format('Y/m/d') }}
                @endscope

                @scope('cell_subject_unit', $case)
                    <span>{{ $case->subjectUnit->name }}</span>
                    @if($case->unit_id !== $case->subject_unit_id)
                        <span class="block text-xs opacity-60">ثبت: {{ $case->unit->name }}</span>
                    @endif
                @endscope

                @scope('cell_actions', $case)
                    <div class="flex gap-1">
                        <x-ui.icon-button
                            name="ویرایش کیس"
                            icon="o-pencil"
                            wire:click="edit({{ $case->id }})"
                            class="btn-ghost btn-xs"
                            tooltip="ویرایش"
                        />
                        <x-ui.icon-button
                            name="حذف کیس"
                            icon="o-trash"
                            wire:click="delete({{ $case->id }})"
                            wire:confirm="این کیس حذف شود؟ این کار بازگشت‌پذیر نیست."
                            class="btn-ghost btn-xs text-error"
                            tooltip="حذف"
                        />
                    </div>
                @endscope
            </x-table>
        @endif
    </x-card>

    <x-modal wire:model="unitModal" title="انتخاب واحد بیمار" persistent separator>
        {{-- Render-on-open (#1171): the tree is built in PHP and must not render
             while the modal is closed. --}}
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
    // Same page-scoped binding as `disease.create` (issue #1182): every date
    // input carries `data-jdp-model` naming its Livewire property.
    const initDiseaseCaseIndexJdp = () => {
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

            const value = $wire.get(input.dataset.jdpModel);
            if (value !== undefined && value !== null) {
                input.value = value;
            }
        });
    };

    initDiseaseCaseIndexJdp();
    document.addEventListener('livewire:navigated', initDiseaseCaseIndexJdp);
    Livewire.hook('morphed', () => initDiseaseCaseIndexJdp());
</script>
@endscript
