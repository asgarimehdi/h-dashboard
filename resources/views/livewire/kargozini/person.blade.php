<div>
    <x-header title="مدیریت پرسنل" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="personnel" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <x-button class="btn-success" wire:click="startCreate" icon="o-plus"/>
            <div class="flex-1">
                <x-input
                    placeholder="جستجو..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
            <x-button icon="o-funnel" class="btn-outline btn-sm" wire:click="$toggle('showFilters')"
                      :class="$showFilters ? 'btn-primary' : ''" />
        </div>

        @if($showFilters)
            <div class="mb-4 p-4 bg-base-200 rounded-lg grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <x-select wire:model.live="filter_s_id" label="سمت" :options="$semats" placeholder="همه سمت‌ها" />
                <x-select wire:model.live="filter_t_id" label="تحصیلات" :options="$tahsils" placeholder="همه سطوح تحصیلات" />
                <x-select wire:model.live="filter_e_id" label="استخدام" :options="$estekhdams" placeholder="همه انواع استخدام" />
                <x-select wire:model.live="filter_r_id" label="ردیف سازمانی" :options="$radifs" placeholder="همه ردیف‌ها" />
                <div>
                    <label class="text-sm font-medium block mb-1">واحد</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex-1 min-w-[12rem] input input-bordered flex items-center">
                            <span class="{{ $filterUnitName ? '' : 'text-base-content/40' }} text-sm">
                                {{ $filterUnitName ?: 'همه واحدها' }}
                            </span>
                        </div>
                        <x-button type="button" label="انتخاب واحد" icon="o-building-office-2"
                                  class="btn-outline btn-sm" wire:click="$set('filterUnitModal', true)" />
                    </div>
                </div>
                <div class="flex items-end">
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm" wire:click="clearFilters" label="پاک کردن فیلترها" />
                </div>
            </div>
        @endif

        <x-modal wire:model="filterUnitModal" title="انتخاب واحد (فیلتر)" persistent separator>
            @include('livewire.partials.unit-tree-picker', [
                'model' => 'filter_u_id',
                'multiple' => false,
                'alwaysOpen' => true,
                'label' => 'واحد سازمانی',
            ])
            <x-slot:actions>
                <x-button label="تأیید" icon="o-check" class="btn-primary" wire:click="$set('filterUnitModal', false)" />
                <x-button label="بستن" icon="o-x-mark" class="btn-ghost" wire:click="$set('filterUnitModal', false)" />
            </x-slot:actions>
        </x-modal>

        @if($formOpen)
            <div class="mb-6 p-4 bg-base-200 rounded-xl border border-base-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-sm">
                        {{ $editingId ? 'ویرایش پرسنل' : 'ثبت پرسنل جدید' }}
                    </h3>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm" wire:click="resetForm" />
                </div>

                <x-form wire:submit.prevent="savePerson" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input wire:model="n_code" label="کد ملی" placeholder="کد ملی" required/>
                    <x-input wire:model="f_name" label="نام" placeholder="نام" required/>
                    <x-input wire:model="l_name" label="نام خانوادگی" placeholder="نام خانوادگی" required/>
                    <x-select wire:model="t_id" label="تحصیلات" :options="$tahsils" required placeholder="انتخاب سطح تحصیلات"/>
                    <x-select wire:model="e_id" label="استخدام" :options="$estekhdams" required placeholder="انتخاب نوع استخدام"/>
                    <x-select wire:model="s_id" label="سمت" :options="$semats" required placeholder="انتخاب سمت"/>
                    <x-select wire:model="r_id" label="ردیف سازمانی" :options="$radifs" required placeholder="انتخاب ردیف سازمانی"/>

                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium block mb-1">واحد</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex-1 min-w-[12rem] input input-bordered flex items-center">
                                <span class="{{ $selectedUnitName ? '' : 'text-base-content/40' }} text-sm">
                                    {{ $selectedUnitName ?: 'واحدی انتخاب نشده' }}
                                </span>
                            </div>
                            <x-button
                                type="button"
                                label="انتخاب واحد"
                                icon="o-building-office-2"
                                class="btn-outline btn-sm"
                                wire:click="$set('unitModal', true)"
                            />
                        </div>
                        @error('u_id') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2 flex justify-end gap-2">
                        <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" spinner />
                        <x-button type="button" label="لغو" wire:click="resetForm" icon="o-x-mark" class="btn-ghost" />
                    </div>
                </x-form>
            </div>
        @endif

        <x-table :headers="$headers" :rows="$persons" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[10, 20, 50]">
            @scope('actions', $person)
                <div class="flex w-1/12">
                    <x-button icon="o-pencil"
                              wire:click="editPerson({{ $person->id }})"
                              class="btn-ghost btn-sm text-primary" />
                    <x-button icon="o-trash"
                              wire:click="delete({{ $person->id }})"
                              wire:confirm="آیا مطمئن هستید"
                              spinner
                              class="btn-ghost btn-sm text-error" />
                </div>
            @endscope
        </x-table>
    </x-card>

    <x-modal wire:model="unitModal" title="انتخاب واحد" persistent separator>
        @include('livewire.partials.unit-tree-picker', [
            'units' => $units,
            'model' => 'u_id',
            'multiple' => false,
            'alwaysOpen' => true,
            'label' => 'واحد سازمانی',
        ])
        <x-slot:actions>
            <x-button label="تأیید" icon="o-check" class="btn-primary" wire:click="$set('unitModal', false)" />
            <x-button label="بستن" icon="o-x-mark" class="btn-ghost" wire:click="$set('unitModal', false)" />
        </x-slot:actions>
    </x-modal>
</div>
