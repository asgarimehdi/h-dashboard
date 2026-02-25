<div>
    <x-header title="ثبت تیکت جدید" separator progress-indicator>
        <x-slot:middle class="!justify-end"></x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>

        {{-- پیام موفقیت --}}
        @if (session()->has('success'))
            <div class="mb-4">
                <x-alert icon="o-check-circle" class="alert-success">
                    <div class="flex justify-between w-full items-center">
                        <span>{{ session('success') }}</span>
                        <span class="font-bold">
                            کد پیگیری: {{ session('ticket_code') }}
                        </span>
                    </div>
                </x-alert>
            </div>
        @endif

        <x-form wire:submit.prevent="saveTicket" class="grid md:grid-cols-12 gap-6">

            {{-- ستون راست --}}
            <div class="md:col-span-7 space-y-4">

                {{-- واحد گیرنده (همان منطق قبلی) --}}
                <div class="relative">
                    <x-input
                        label="واحد گیرنده"
                        placeholder="جستجوی واحد..."
                        wire:model.live.debounce.300ms="search"
                        icon="o-magnifying-glass"
                    />

                    {{-- مهم: چون منطق قبلی unit_id جدا ست می‌شود --}}
                    <input type="hidden" wire:model="unit_id">

                    @if($showDropdown && !empty($units))
                        <div class="absolute z-50 w-full mt-1 bg-base-100 border rounded-box shadow-lg max-h-48 overflow-auto">
                            @foreach($units as $unit)
                                <button type="button"
                                        wire:click="selectUnit({{ $unit->id }}, '{{ $unit->name }}')"
                                        class="w-full text-right px-3 py-2 hover:bg-base-200 transition text-sm border-b last:border-0">
                                    {{ $unit->name }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @error('unit_id')
                        <span class="text-error text-xs">{{ $message }}</span>
                    @enderror
                </div>

                {{-- اولویت --}}
                <x-radio
                    label="فوریت"
                    wire:model="priority"
                    :options="[
                        ['id' => 'low', 'name' => 'عادی'],
                        ['id' => 'normal', 'name' => 'متوسط'],
                        ['id' => 'urgent', 'name' => 'فوری']
                    ]"
                />

                {{-- موضوع --}}
                <x-input
                    label="موضوع تیکت"
                    placeholder="عنوان کوتاه..."
                    wire:model="subject"
                />
                @error('subject')
                    <span class="text-error text-xs">{{ $message }}</span>
                @enderror

                {{-- شرح درخواست --}}
                <x-textarea
                    label="شرح درخواست"
                    rows="4"
                    wire:model="content"
                    placeholder="جزئیات مشکل خود را بنویسید..."
                />
                @error('content')
                    <span class="text-error text-xs">{{ $message }}</span>
                @enderror
            </div>

            {{-- ستون چپ --}}
            <div class="md:col-span-5 space-y-4">

                {{-- آپلود فایل (همان منطق قبلی) --}}
                <div>
                    <x-file
                        label="پیوست مستندات"
                        wire:model="files"
                        multiple
                        hint="حداکثر ۵ فایل (مجموع ۱۰ مگ)"
                    />

                    {{-- لیست فایل‌ها --}}
                    @if($files)
                        <div class="flex flex-wrap gap-2 mt-3">
                            @foreach($files as $index => $file)
                                <x-badge class="badge-outline gap-2">
                                    {{ $file->getClientOriginalName() }}
                                    <x-button
                                        icon="o-x-mark"
                                        wire:click="removeFile({{ $index }})"
                                        class="btn-ghost btn-xs text-error"
                                    />
                                </x-badge>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- دکمه ثبت --}}
                <div class="pt-4 border-t">
                    <x-button
                        type="submit"
                        label="ارسال نهایی و دریافت کد"
                        icon="o-check"
                        class="btn-primary w-full"
                        spinner
                    />
                </div>

            </div>

        </x-form>

    </x-card>
</div>