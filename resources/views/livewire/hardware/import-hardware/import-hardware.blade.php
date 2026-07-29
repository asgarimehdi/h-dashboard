<div class="card w-full bg-base-100 shadow-xl">
    <div class="card-body">
        <div class="flex items-center justify-between mb-6">
            <h2 class="card-title text-2xl flex items-center gap-3">
                <x-icon name="o-arrow-down-tray" class="w-6 h-6 text-primary" />
                ورود اطلاعات شناسنامه سخت‌افزار از فایل اکسل
            </h2>
            <x-help:button section="hardware-import" wireModel="showHelpModal" />
        </div>
        
        <x-help:modal wireModel="showHelpModal" />

        <div class="alert alert-info mb-6">
            <x-icon name="o-info-circle" class="w-5 h-5" />
            <span>
                فرمت‌های پشتیبانی شده: <strong>.xlsx، .xls، .csv</strong> (حداکثر ۱۰ مگابایت).
                فایل باید شامل ستون‌های: <code class="px-1 bg-base-200 rounded">n_code</code>، <code class="px-1 bg-base-200 rounded">pc_name</code>، <code class="px-1 bg-base-200 rounded">type</code>، <code class="px-1 bg-base-200 rounded">os</code>، <code class="px-1 bg-base-200 rounded">ip_local</code>، <code class="px-1 bg-base-200 rounded">mac</code> و سایر فیلدهای سخت‌افزار باشد.
                مقایسه بر اساس <strong>نام دستگاه (pc_name)</strong> یا <strong>آدرس MAC</strong> انجام می‌شود.
            </span>
        </div>

        @if (!$showPreview)
            <!-- File Upload Step -->
            <div class="space-y-6">
                <div class="join join-vertical w-full max-w-xl">
                    <label class="input input-bordered join-item w-full max-w-xl" for="file-upload">
                        <input
                            type="file"
                            id="file-upload"
                            wire:model="file"
                            class="file-input file-input-bordered w-full max-w-xl"
                            accept=".xlsx,.xls,.csv"
                        />
                    </label>
                    @error('file')
                        <div class="alert alert-error join-item w-full max-w-xl">
                            <x-icon name="o-x-circle" class="w-5 h-5" />
                            <span>{{ $message }}</span>
                        </div>
                    @enderror
                </div>

                <div class="flex items-center gap-4">
                    <label class="cursor-pointer label">
                        <input type="radio" name="compareKey" value="pc_name" wire:model="compareKey" class="radio radio-primary" />
                        <span class="label-text">مقایسه بر اساس نام دستگاه (pc_name)</span>
                    </label>
                    <label class="cursor-pointer label">
                        <input type="radio" name="compareKey" value="mac" wire:model="compareKey" class="radio radio-primary" />
                        <span class="label-text">مقایسه بر اساس آدرس MAC</span>
                    </label>
                    <label class="cursor-pointer label">
                        <input type="radio" name="compareKey" value="both" wire:model="compareKey" class="radio radio-primary" />
                        <span class="label-text">هر دو (اول pc_name، سپس mac)</span>
                    </label>
                </div>

                <x-button wire:click="importPreview" class="btn-primary w-full max-w-xl" :loading="wire:loading">
                    <x-icon name="o-magnifying-glass" class="w-5 h-5" />
                    پیش‌نمایش و مقایسه
                </x-button>
            </div>
        @else
            <!-- Preview Step -->
            <div class="space-y-6">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <h3 class="text-xl font-semibold">پیش‌نمایش تغییرات ({{ $importStats['total'] }} رکورد)</h3>
                    <div class="flex items-center gap-4 text-sm">
                        <span class="badge badge-success gap-1"><x-icon name="o-plus-circle" class="w-4 h-4" /> {{ $importStats['new'] }} جدید</span>
                        <span class="badge badge-warning gap-1"><x-icon name="o-arrow-path" class="w-4 h-4" /> {{ $importStats['updated'] }} بروزرسانی</span>
                        <span class="badge badge-info gap-1"><x-icon name="o-minus-circle" class="w-4 h-4" /> {{ $importStats['unchanged'] }} بدون تغییر</span>
                        @if($importStats['errors'] > 0)
                            <span class="badge badge-error gap-1"><x-icon name="o-x-circle" class="w-4 h-4" /> {{ $importStats['errors'] }} خطا</span>
                        @endif
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th class="w-10">#</th>
                                <th>عملیات</th>
                                <th>نام دستگاه</th>
                                <th>کد پرسنلی</th>
                                <th>نوع</th>
                                <th>CPU</th>
                                <th>RAM</th>
                                <th>HDD</th>
                                <th>MAC</th>
                                <th>تغییرات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($previewData as $index => $row)
                                <tr class="{{ $row['action'] === 'create' ? 'bg-success/10' : ($row['action'] === 'update' ? 'bg-warning/10' : 'bg-base-100') }}">
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <select
                                            wire:model="previewData.{{ $index }}.selected_action"
                                            class="select select-sm w-full max-w-xs"
                                            @if($row['action'] === 'skip') disabled @endif
                                        >
                                            <option value="create" @if($row['action'] === 'create') selected @endif>ایجاد جدید</option>
                                            <option value="update" @if($row['action'] === 'update') selected @endif>بروزرسانی</option>
                                            <option value="skip" @if($row['action'] === 'skip') selected @endif>نادیده بگیر</option>
                                        </select>
                                    </td>
                                    <td class="font-mono font-medium">{{ $row['pc_name'] ?? '-' }}</td>
                                    <td>{{ $row['n_code'] ?? '-' }}</td>
                                    <td>{{ $row['type'] ?? '-' }}</td>
                                    <td class="font-mono text-xs">{{ $row['cpu'] ?? '-' }}</td>
                                    <td>{{ $row['ram'] ?? '-' }}</td>
                                    <td>{{ $row['hdd'] ?? '-' }}</td>
                                    <td class="font-mono text-xs">{{ $row['mac'] ?? '-' }}</td>
                                    <td>
                                        @if(!empty($row['changes']))
                                            <div class="space-y-1">
                                                @foreach($row['changes'] as $field => $change)
                                                    <div class="text-xs">
                                                        <span class="badge badge-ghost badge-xs">{{ $field }}</span>
                                                        <span class="text-base-content/50">:</span>
                                                        <span class="badge badge-error badge-xs">{{ $change['old'] ?? '—' }}</span>
                                                        <x-icon name="o-arrow-right" class="w-3 h-3 inline" />
                                                        <span class="badge badge-success badge-xs">{{ $change['new'] }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-base-content/50 text-sm">بدون تغییر</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($importStats['errors'] > 0)
                    <div class="alert alert-error">
                        <x-icon name="o-alert-triangle" class="w-5 h-5" />
                        <span>{{ $importStats['errors'] }} ردیف با خطای اعتبارسنجی مواجه شده و نادیده گرفته می‌شوند.</span>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-sm underline">مشاهده خطاها</summary>
                            <pre class="mt-2 p-3 bg-base-200 rounded text-xs overflow-auto">{{ json_encode($importResults['errors'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <x-button wire:click="cancelImport" variant="ghost">
                        <x-icon name="o-x" class="w-5 h-5" />
                        انصراف
                    </x-button>
                    <x-button wire:click="confirmImport" class="btn-primary" :loading="wire:loading">
                        <x-icon name="o-check" class="w-5 h-5" />
                        تایید و انجام ایمپورت
                    </x-button>
                </div>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('hardware-imported', () => {
            // Optionally refresh the hardware table
            if (window.Livewire) {
                const component = document.querySelector('[wire\\:id]');
                if (component) {
                    Livewire.find(component.getAttribute('wire:id')).dispatch('refreshHardware');
                }
            }
        });
    });
</script>
@endpush