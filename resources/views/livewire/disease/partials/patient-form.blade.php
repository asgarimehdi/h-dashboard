{{--
    Shared patient identity form (#1243 / #1244).
    Contract: the host component owns the six properties and calls
    PatientService::validationRules()/create()/duplicateMessage(); this partial
    renders inputs only (no submit button). `$genderOptions` is passed in by the
    host — included partials in this repo never use `$this`.
--}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div class="md:col-span-2">
        <x-input
            label="کد ملی"
            wire:model="kod_melli"
            dir="ltr"
            placeholder="۱۰ رقم"
            icon="o-identification"
        />

        @error('kod_melli')
            <p class="text-xs text-error mt-1">{{ $message }}</p>
        @enderror
    </div>

    <x-input label="نام" wire:model="first_name" placeholder="نام" />

    <x-input label="نام خانوادگی" wire:model="last_name" placeholder="نام خانوادگی" />

    <div class="form-control">
        <label class="label" for="patient_birth_date">
            <span class="label-text">تاریخ تولد (جلالی)</span>
        </label>
        {{-- `wire:ignore`: the Jalali picker owns the DOM value; `@script`
             mirrors it into `birth_date_jalali` and back. --}}
        <input
            id="patient_birth_date"
            data-jdp
            data-jdp-time="false"
            data-jdp-format="YYYY/MM/DD"
            wire:ignore
            readonly
            dir="ltr"
            class="input input-bordered w-full"
            placeholder="YYYY/MM/DD"
        >
        @error('birth_date_jalali')
            <p class="text-xs text-error mt-1">{{ $message }}</p>
        @enderror
    </div>

    <x-select
        label="جنسیت"
        wire:model="gender"
        :options="$genderOptions"
        option-value="value"
        option-label="label"
        placeholder="انتخاب کنید"
    />

    <x-input label="شهرستان" wire:model="city" placeholder="اختیاری" />
</div>
