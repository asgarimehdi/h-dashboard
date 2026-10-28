{{--
    Dynamic disease-case fields (#1244), shared by create and edit.
    The host component supplies `$defs` (active DiseaseFieldDefs) and
    `$selectOptions` (field_key → value/label options); the state lives in the
    host's `fields` array, keyed by field_key. Inputs only — no submit.

    Every row carries `wire:key`; without it Livewire's morph shifts values
    between dynamic rows.
--}}
@foreach($defs as $def)
    <div @class(['form-control w-full', 'md:col-span-2' => $def->type === 'text']) wire:key="field-{{ $def->field_key }}">
        @if($def->type === 'number')
            <x-input
                label="{{ $def->label_fa }}{{ $def->unit ? ' ('.$def->unit.')' : '' }}"
                type="number"
                wire:model="fields.{{ $def->field_key }}"
                :required="$def->required"
            />
        @elseif($def->type === 'select')
            <x-select
                label="{{ $def->label_fa }}"
                wire:model="fields.{{ $def->field_key }}"
                :options="$selectOptions[$def->field_key] ?? []"
                option-value="value"
                option-label="label"
                placeholder="انتخاب کنید"
                :required="$def->required"
            />
        @elseif($def->type === 'date')
            <label class="label" for="disease_case_field_{{ $def->field_key }}">
                <span class="label-text">{{ $def->label_fa }}</span>
            </label>
            {{-- `wire:ignore`: the Jalali picker owns the DOM value; the host
                 page's `@script` mirrors it into `fields.<key>` and back. --}}
            <input
                id="disease_case_field_{{ $def->field_key }}"
                data-jdp
                data-jdp-model="fields.{{ $def->field_key }}"
                wire:ignore
                readonly
                dir="ltr"
                class="input input-bordered w-full"
                placeholder="YYYY/MM/DD"
                value="{{ $fields[$def->field_key] ?? '' }}"
                @if($def->required) required @endif
            >
        @else
            <x-input
                label="{{ $def->label_fa }}"
                wire:model="fields.{{ $def->field_key }}"
                maxlength="{{ \App\Services\Disease\DiseaseFieldValidator::MAX_TEXT_LENGTH }}"
                :required="$def->required"
            />
        @endif

        @error('fields.'.$def->field_key)
            <p class="text-xs text-error mt-1">{{ $message }}</p>
        @enderror
    </div>
@endforeach
