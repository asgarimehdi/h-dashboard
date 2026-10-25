<?php

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\DiseaseFieldDef;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    // ── disease form state ──
    public ?int $selectedDiseaseId = null;

    public bool $showDiseaseForm = false;

    public ?int $editingDiseaseId = null;

    public string $nameFa = '';

    public string $slug = '';

    public bool $isActive = true;

    public int $sort = 0;

    // ── field def form state ──
    public ?int $editingFieldId = null;

    public string $fieldKey = '';

    public string $labelFa = '';

    public string $type = 'number';

    public ?string $unit = null;

    public string $optionsText = '';

    public bool $isRequired = false;

    public int $fieldSort = 0;

    // ── listing state ──
    public string $search = '';

    public int $perPage = 20;

    public function mount(): void
    {
        $this->authorize('manage_diseases');
    }

    public function selectDisease(int $id): void
    {
        $this->authorize('manage_diseases');

        Disease::query()->findOrFail($id);
        $this->selectedDiseaseId = $id;
        $this->resetFieldForm();
    }

    public function startCreateDisease(): void
    {
        $this->authorize('manage_diseases');

        $this->resetValidation();
        $this->reset(['nameFa', 'slug', 'isActive', 'sort', 'editingDiseaseId']);
        // Decision «و»: server-side default; duplicates are allowed (display order only).
        $this->sort = (int) Disease::query()->max('sort') + 1;
        $this->showDiseaseForm = true;
    }

    public function editDisease(int $id): void
    {
        $this->authorize('manage_diseases');

        $disease = Disease::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingDiseaseId = $disease->id;
        $this->selectedDiseaseId = $disease->id;
        $this->nameFa = $disease->name_fa;
        $this->slug = $disease->slug;
        $this->isActive = $disease->is_active;
        $this->sort = $disease->sort;
        $this->showDiseaseForm = true;
    }

    public function cancelDiseaseEdit(): void
    {
        $this->authorize('manage_diseases');

        $this->resetValidation();
        $this->reset(['nameFa', 'slug', 'isActive', 'sort', 'editingDiseaseId', 'showDiseaseForm']);
    }

    public function saveDisease(): void
    {
        $this->authorize('manage_diseases');

        $this->validate([
            'nameFa' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]*$/',
                Rule::unique('diseases', 'slug')->ignore($this->editingDiseaseId)],
            'isActive' => 'boolean',
            'sort' => 'required|integer|min:0',
        ]);

        if ($this->editingDiseaseId !== null) {
            $disease = Disease::query()->findOrFail($this->editingDiseaseId);

            $payload = [
                'name_fa' => $this->nameFa,
                'is_active' => $this->isActive,
                'sort' => $this->sort,
            ];

            // Decision «ه»: once cases exist the slug is a consumed machine key.
            // The form disables the input; keep the stored value even if a
            // tampered payload still submits a different one.
            if (! $this->diseaseHasCases($disease)) {
                $payload['slug'] = $this->slug;
            }

            $disease->update($payload);

            $this->selectedDiseaseId = $disease->id;
            $this->success("«{$disease->name_fa}» بروزرسانی شد", 'با موفقیت', position: 'toast-bottom');
        } else {
            $disease = Disease::query()->create([
                'name_fa' => $this->nameFa,
                'slug' => $this->slug,
                'is_active' => $this->isActive,
                'sort' => $this->sort,
            ]);

            $this->selectedDiseaseId = $disease->id;
            $this->success("«{$disease->name_fa}» ثبت شد", 'با موفقیت', position: 'toast-bottom');
        }

        $this->resetValidation();
        $this->reset(['nameFa', 'slug', 'isActive', 'sort', 'editingDiseaseId', 'showDiseaseForm']);
    }

    public function toggleDiseaseActive(int $id): void
    {
        $this->authorize('manage_diseases');

        $disease = Disease::query()->findOrFail($id);
        $disease->is_active = ! $disease->is_active;
        $disease->save();

        $this->success(
            $disease->is_active ? "«{$disease->name_fa}» فعال شد" : "«{$disease->name_fa}» غیرفعال شد",
            'با موفقیت',
            position: 'toast-bottom'
        );
    }

    public function deleteDisease(int $id): void
    {
        $this->authorize('manage_diseases');

        $disease = Disease::query()->findOrFail($id);

        if (DiseaseCase::query()->where('disease_id', $disease->id)->exists()) {
            $this->error('این بیماری کیس ثبت‌شده دارد؛ حذف ممکن نیست — آن را غیرفعال کنید.', position: 'toast-bottom');

            return;
        }

        $disease->delete();

        if ($this->selectedDiseaseId === $id) {
            $this->selectedDiseaseId = null;
        }

        if ($this->editingDiseaseId === $id) {
            $this->cancelDiseaseEdit();
        }
    }

    // ── field def CRUD ────────────────────────────────────────────────────

    /** @return array<int, array{value: string, label: string}> */
    public function typeOptions(): array
    {
        return [
            ['value' => 'number', 'label' => 'عددی'],
            ['value' => 'text', 'label' => 'متنی'],
            ['value' => 'select', 'label' => 'انتخابی'],
            ['value' => 'date', 'label' => 'تاریخ'],
        ];
    }

    public function typeLabel(string $type): string
    {
        foreach ($this->typeOptions() as $option) {
            if ($option['value'] === $type) {
                return $option['label'];
            }
        }

        return $type;
    }

    public function startCreateFieldDef(): void
    {
        $this->authorize('manage_diseases');

        if ($this->selectedDiseaseId === null) {
            $this->error('ابتدا یک بیماری انتخاب کنید.', position: 'toast-bottom');

            return;
        }

        $this->resetValidation();
        $this->resetFieldForm();
    }

    public function editFieldDef(int $id): void
    {
        $this->authorize('manage_diseases');

        $def = DiseaseFieldDef::query()->findOrFail($id);

        $this->resetValidation();
        $this->selectedDiseaseId = $def->disease_id;
        $this->editingFieldId = $def->id;
        $this->fieldKey = $def->field_key;
        $this->labelFa = $def->label_fa;
        $this->type = $def->type;
        $this->unit = $def->unit;
        $this->optionsText = implode("\n", $def->options ?? []);
        $this->isRequired = $def->required;
        $this->fieldSort = $def->sort;
    }

    public function saveFieldDef(): void
    {
        $this->authorize('manage_diseases');

        if ($this->selectedDiseaseId === null) {
            $this->error('ابتدا یک بیماری انتخاب کنید.', position: 'toast-bottom');

            return;
        }

        $rules = [
            'labelFa' => 'required|string|max:255',
            'type' => 'required|in:number,text,select,date',
            'unit' => 'nullable|string|max:50',
            'optionsText' => 'required_if:type,select|nullable|string',
            'isRequired' => 'boolean',
            'fieldSort' => 'required|integer|min:0',
        ];

        // `field_key` is the JSON key on every stored case, so it is only
        // accepted at creation time (decision «ه»-adjacent, §4 note 4).
        if ($this->editingFieldId === null) {
            $rules['fieldKey'] = ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('disease_field_defs', 'field_key')
                    ->where('disease_id', $this->selectedDiseaseId)
                    ->ignore($this->editingFieldId)];
        }

        $this->validate($rules);

        $options = null;

        if ($this->type === 'select') {
            $options = array_values(array_unique(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $this->optionsText) ?: []),
                // `array_filter` without a callback drops the string "0"; an
                // option value of zero is legitimate, only empty lines go.
                static fn (string $value): bool => $value !== ''
            )));

            if ($options === []) {
                $this->addError('optionsText', 'حداقل یک گزینه لازم است.');

                return;
            }
        } elseif (trim($this->optionsText) !== '') {
            $this->addError('optionsText', 'گزینه‌ها فقط برای نوع انتخابی مجاز است.');

            return;
        }

        $payload = [
            'label_fa' => $this->labelFa,
            'type' => $this->type,
            'unit' => $this->unit,
            'options' => $options,
            'required' => $this->isRequired,
            'sort' => $this->fieldSort,
        ];

        if ($this->editingFieldId !== null) {
            $def = DiseaseFieldDef::query()->findOrFail($this->editingFieldId);
            // field_key and is_active are deliberately absent.
            $def->update($payload);
        } else {
            DiseaseFieldDef::query()->create([
                'disease_id' => $this->selectedDiseaseId,
                'field_key' => $this->fieldKey,
                // New definitions are visible on new forms (decision «الف»).
                'is_active' => true,
                ...$payload,
            ]);
        }

        $this->success("«{$this->labelFa}» ذخیره شد", 'با موفقیت', position: 'toast-bottom');
        $this->resetValidation();
        $this->resetFieldForm();
    }

    public function deleteFieldDef(int $id): void
    {
        $this->authorize('manage_diseases');

        $def = DiseaseFieldDef::query()->findOrFail($id);

        // The gate correction: `jsonb_exists` does not exist on this
        // PostgreSQL 16, and `fields ? ?` collides with PDO placeholders.
        $hasData = DiseaseCase::query()
            ->where('disease_id', $def->disease_id)
            ->whereRaw('jsonb_exists_any(fields, ARRAY[?])', [$def->field_key])
            ->exists();

        if ($hasData) {
            $this->error('این فیلد برای بعضی کیس‌ها مقدار ثبت‌شده دارد؛ حذف نمی‌شود.', position: 'toast-bottom');

            return;
        }

        $def->delete();

        if ($this->editingFieldId === $id) {
            $this->resetFieldForm();
        }
    }

    private function resetFieldForm(): void
    {
        $this->reset([
            'editingFieldId', 'fieldKey', 'labelFa', 'type', 'unit',
            'optionsText', 'isRequired', 'fieldSort',
        ]);
    }

    // ── selection helpers ─────────────────────────────────────────────────

    public function selectedDisease(): ?Disease
    {
        if ($this->selectedDiseaseId === null) {
            return null;
        }

        return Disease::query()->find($this->selectedDiseaseId);
    }

    /** @return Collection<int, DiseaseFieldDef> */
    public function fields(): Collection
    {
        if ($this->selectedDiseaseId === null) {
            return new Collection();
        }

        return DiseaseFieldDef::query()
            ->where('disease_id', $this->selectedDiseaseId)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    public function fieldHeaders(): array
    {
        return [
            ['key' => 'field_key', 'label' => 'کلید'],
            ['key' => 'label_fa', 'label' => 'برچسب'],
            ['key' => 'type', 'label' => 'نوع'],
            ['key' => 'unit', 'label' => 'واحد'],
            ['key' => 'required', 'label' => 'الزامی', 'sortable' => false],
            ['key' => 'sort', 'label' => 'ترتیب'],
        ];
    }

    /** Decision «ه»: the edit form locks `slug` once a case references it. */
    public function slugLocked(): bool
    {
        if ($this->editingDiseaseId === null) {
            return false;
        }

        return $this->diseaseHasCases(Disease::query()->findOrFail($this->editingDiseaseId));
    }

    private function diseaseHasCases(Disease $disease): bool
    {
        return DiseaseCase::query()->where('disease_id', $disease->id)->exists();
    }

    // ── listing ───────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function headers(): array
    {
        return [
            ['key' => 'name_fa', 'label' => 'نام بیماری', 'class' => 'flex-1'],
            ['key' => 'slug', 'label' => 'کلید (slug)'],
            ['key' => 'is_active', 'label' => 'وضعیت', 'sortable' => false],
            ['key' => 'sort', 'label' => 'ترتیب'],
            ['key' => 'field_defs_count', 'label' => 'تعداد فیلد', 'sortable' => false],
        ];
    }

    /** @return LengthAwarePaginator<int, Disease> */
    public function diseases(): LengthAwarePaginator
    {
        $query = Disease::query()->withCount('fieldDefs');

        if ($this->search !== '') {
            $term = PersianNormalizer::foldedTerm($this->search);
            $query->whereRaw(PersianNormalizer::foldSeparatorsSql('name_fa').' LIKE ?', ["%{$term}%"]);
        }

        return $query->orderBy('sort')->orderBy('id')->paginate($this->perPage);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'diseases' => $this->diseases(),
            'headers' => $this->headers(),
            'fields' => $this->fields(),
            'fieldHeaders' => $this->fieldHeaders(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت بیماری‌ها" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2">
            <x-card shadow>
                <div class="flex gap-2 items-center mb-4">
                    <x-ui.icon-button name="بیماری جدید" label="بیماری جدید" class="btn-success" wire:click="startCreateDisease" icon="o-plus" responsive/>
                    <div class="flex-1">
                        <x-input
                            placeholder="جستجو..."
                            wire:model.live.debounce="search"
                            clearable
                            icon="o-magnifying-glass"
                            class="w-full"
                        />
                    </div>
                </div>

                <x-table :headers="$headers" :rows="$diseases" with-pagination per-page="perPage"
                         :per-page-values="[10, 20, 50]">
                    @scope('cell_slug', $disease)
                        <span class="font-mono text-xs" dir="ltr">{{ $disease->slug }}</span>
                    @endscope

                    @scope('cell_is_active', $disease)
                        @if($disease->is_active)
                            <span class="badge badge-success badge-sm">فعال</span>
                        @else
                            <span class="badge badge-ghost badge-sm">غیرفعال</span>
                        @endif
                    @endscope

                    @scope('actions', $disease)
                        <div class="flex gap-1 items-center">
                            <x-ui.icon-button name="فیلدهای بیماری"
                                icon="o-list-bullet"
                                wire:click="selectDisease({{ $disease->id }})"
                                class="btn-ghost btn-circle btn-sm {{ $this->selectedDiseaseId === $disease->id ? 'text-primary' : '' }}"
                                title="فیلدهای بیماری"
                            />
                            <x-ui.icon-button name="ویرایش بیماری"
                                icon="o-pencil"
                                wire:click="editDisease({{ $disease->id }})"
                                class="btn-ghost btn-circle btn-sm text-primary"
                                title="ویرایش"
                            />
                            <x-ui.icon-button name="فعال یا غیرفعال کردن بیماری"
                                icon="{{ $disease->is_active ? 'o-eye-slash' : 'o-eye' }}"
                                wire:click="toggleDiseaseActive({{ $disease->id }})"
                                class="btn-ghost btn-circle btn-sm"
                                title="{{ $disease->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}"
                            />
                            <x-ui.icon-button name="حذف بیماری"
                                icon="o-trash"
                                wire:click="deleteDisease({{ $disease->id }})"
                                wire:confirm="آیا مطمئن هستید؟"
                                spinner
                                class="btn-ghost btn-circle btn-sm text-error"
                                title="حذف"
                            />
                        </div>
                    @endscope
                </x-table>
            </x-card>
        </div>

        <div>
            <x-card shadow>
                @if($showDiseaseForm)
                    <h3 class="font-bold mb-3">{{ $editingDiseaseId ? 'ویرایش بیماری' : 'بیماری جدید' }}</h3>

                    <div class="flex flex-col gap-3">
                        <div>
                            <x-input wire:model="nameFa" label="نام بیماری" placeholder="مثلاً فشار خون" required/>
                            @error('nameFa') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <x-input wire:model="slug" label="کلید (slug)"
                                     placeholder="مثلاً htn"
                                     dir="ltr"
                                     :disabled="$this->slugLocked()"/>
                            @if($this->slugLocked())
                                <span class="text-xs text-base-content/60">
                                    پس از ثبت کیس، کلید بیماری قابل ویرایش نیست.
                                </span>
                            @endif
                            @error('slug') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>

                        <div class="flex gap-3 items-end">
                            <div class="flex-1">
                                <x-input wire:model="sort" label="ترتیب" type="number" min="0"/>
                                @error('sort') <span class="text-error text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="pb-2">
                                <x-toggle wire:model="isActive" label="فعال"/>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <x-button wire:click="saveDisease" label="ذخیره" icon="o-check" class="btn-primary" spinner/>
                            <x-button wire:click="cancelDiseaseEdit" label="لغو" icon="o-x-mark" class="btn-ghost"/>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-base-content/60">
                        برای افزودن بیماری جدید از دکمهٔ «بیماری جدید» استفاده کنید.
                    </p>
                @endif
            </x-card>

            @php $disease = $this->selectedDisease(); @endphp
            @if($disease !== null)
                <div class="mt-4">
                    <x-card shadow>
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h3 class="font-bold">فیلدهای «{{ $disease->name_fa }}»</h3>
                            <x-ui.icon-button name="فیلد جدید" label="فیلد جدید" class="btn-success btn-sm"
                                             wire:click="startCreateFieldDef" icon="o-plus" responsive/>
                        </div>

                        <div class="flex flex-col gap-3 p-3 bg-base-200 rounded-lg mb-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <x-input wire:model="fieldKey" label="کلید" dir="ltr" placeholder="sys"
                                             :disabled="$editingFieldId !== null"/>
                                    @if($editingFieldId !== null)
                                        <span class="text-xs text-base-content/60">کلید پس از ثبت قابل ویرایش نیست.</span>
                                    @endif
                                    @error('fieldKey') <span class="text-error text-xs">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <x-input wire:model="labelFa" label="برچسب" placeholder="مثلاً فشار سیستولیک"/>
                                    @error('labelFa') <span class="text-error text-xs">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    {{-- option-value/option-label are required: MaryUI defaults to
                                         id/name, so value/label keys would render blank options (#706). --}}
                                    <x-select wire:model="type" label="نوع"
                                              :options="$this->typeOptions()"
                                              option-value="value"
                                              option-label="label"/>
                                    @error('type') <span class="text-error text-xs">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <x-input wire:model="unit" label="واحد (اختیاری)" placeholder="مثلاً mmHg"/>
                                    @error('unit') <span class="text-error text-xs">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <x-input wire:model="fieldSort" label="ترتیب" type="number" min="0"/>
                                    @error('fieldSort') <span class="text-error text-xs">{{ $message }}</span> @enderror
                                </div>

                                <div class="self-end pb-2">
                                    <x-toggle wire:model="isRequired" label="الزامی"/>
                                </div>
                            </div>

                            <div>
                                <x-textarea wire:model="optionsText"
                                            label="گزینه‌ها (هر مقدار در یک خط)"
                                            placeholder="I&#10;II&#10;III"
                                            rows="3"/>
                                <span class="text-xs text-base-content/60">فقط برای نوع «انتخابی».</span>
                                @error('optionsText') <span class="text-error text-xs">{{ $message }}</span> @enderror
                            </div>

                            <div class="flex gap-2">
                                <x-button wire:click="saveFieldDef" label="ذخیره فیلد" icon="o-check"
                                          class="btn-primary" spinner/>
                                <x-button wire:click="startCreateFieldDef" label="فرم خالی" icon="o-x-mark"
                                          class="btn-ghost"/>
                            </div>
                        </div>

                        <x-table :headers="$fieldHeaders" :rows="$fields">
                            @scope('cell_field_key', $field)
                                <span dir="ltr" class="font-mono text-xs">{{ $field->field_key }}</span>
                            @endscope

                            @scope('cell_type', $field)
                                {{ $this->typeLabel($field->type) }}
                            @endscope

                            @scope('cell_required', $field)
                                @if($field->required)
                                    <span class="badge badge-warning badge-sm">الزامی</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">اختیاری</span>
                                @endif
                            @endscope

                            @scope('actions', $field)
                                <div class="flex gap-1">
                                    <x-ui.icon-button name="ویرایش فیلد" icon="o-pencil"
                                                     wire:click="editFieldDef({{ $field->id }})"
                                                     class="btn-ghost btn-circle btn-sm text-primary"
                                                     title="ویرایش"/>
                                    <x-ui.icon-button name="حذف فیلد" icon="o-trash"
                                                     wire:click="deleteFieldDef({{ $field->id }})"
                                                     wire:confirm="آیا مطمئن هستید؟" spinner
                                                     class="btn-ghost btn-circle btn-sm text-error"
                                                     title="حذف"/>
                                </div>
                            @endscope
                        </x-table>
                    </x-card>
                </div>
            @endif
        </div>
    </div>
</div>
