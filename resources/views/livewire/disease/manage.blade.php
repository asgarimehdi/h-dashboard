<?php

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Traits\PersianNormalizer;
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
        </div>
    </div>
</div>
