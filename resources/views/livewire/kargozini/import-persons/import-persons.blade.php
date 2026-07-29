<div class="card w-full bg-base-100 shadow-xl">
    <div class="card-body">
        <div class="flex items-center justify-between mb-6">
            <h2 class="card-title text-2xl flex items-center gap-3">
                <x-icon name="o-arrow-down-tray" class="w-6 h-6 text-primary" />
                ورود اطلاعات پرسنل از فایل اکسل
            </h2>
            <x-help:button section="persons-import" wireModel="showHelpModal" />
        </div>

        <x-help:modal wireModel="showHelpModal" />

        <div class="alert alert-info mb-6">
            <x-icon name="o-info-circle" class="w-5 h-5" />
            <span>
                فرمت‌های پشتیبانی شده: <strong>.xlsx، .xls، .csv</strong> (حداکثر ۱۰ مگابایت).
                فایل باید شامل ستون‌های: <code class="px-1 bg-base-200 rounded">n_code</code>، <code class="px-1 bg-base-200 rounded">f_name</code>، <code class="px-1 bg-base-200 rounded">l_name</code>، <code class="px-1 bg-base-200 rounded">t_id</code>، <code class="px-1 bg-base-200 rounded">e_id</code>، <code class="px-1 bg-base-200 rounded">s_id</code>، <code class="px-1 bg-base-200 rounded">r_id</code>، <code class="px-1 bg-base-200 rounded">u_id</code> باشد.
                مقایسه و مطابقة بر اساس <strong>کد ملی (n_code)</code> انجام می‌شود.
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
                                <th>کد ملی</th>
                                <th>نام</th>
                                <th>نام خانوادگی</th>
                                <th>تحصیلات</th>
                                <th>نوع استخدام</th>
                                <th>سمت</th>
                                <th>ردیف</th>
                                <th>واحد</th>
                                <th>تغییرات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($previewData as $index => $row)
                                <tr class="{{ $row['status'] === 'create' ? 'bg-success/10' : ($row['status'] === 'update' ? 'bg-warning/10' : 'bg-base-100') }}">
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <select
                                            wire:model="previewData.{{ $index }}.selected_action"
                                            class="select select-sm w-full max-w-xs"
                                            @if($row['status'] === 'error') disabled @endif
                                        >
                                            <option value="create" @if($row['status'] === 'create') selected @endif>ایجاد جدید</option>
                                            <option value="update" @if($row['status'] === 'update') selected @endif>بروزرسانی</option>
                                            <option value="skip" @if($row['status'] === 'skip' || $row['status'] === 'unchanged' || $row['status'] === 'error') selected @endif>نادیده بگیر</option>
                                        </select>
                                    </td>
                                    <td class="font-mono font-medium">{{ $row['data']['n_code'] ?? '-' }}</td>
                                    <td>{{ $row['data']['f_name'] ?? '-' }}</td>
                                    <td>{{ $row['data']['l_name'] ?? '-' }}</td>
                                    <td>{{ $row['data']['t_id'] ?? '-' }}</td>
                                    <td>{{ $row['data']['e_id'] ?? '-' }}</td>
                                    <td>{{ $row['data']['s_id'] ?? '-' }}</td>
                                    <td>{{ $row['data']['r_id'] ?? '-' }}</td>
                                    <td>{{ $row['data']['u_id'] ?? '-' }}</td>
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
    </div</div>

@push('scripts')
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('persons-imported', () => {
            // Optionally refresh the persons table
            if (window.Livewire) {
                const component = document.querySelector('[wire\:id]');
                if (component) {
                    Livewire.find(component.getAttribute('wire:id')).dispatch('refreshPersons');
                }
            }
        });
    });
</script>
@endpush