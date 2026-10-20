<?php
use App\Imports\HardwareImport;
use App\Jobs\ImportHardwareJob;
use App\Services\AccessService;
use App\Support\ImportStatus;
use App\Traits\PersianNormalizer;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use Mary\Traits\Toast;
return new class extends Component
{
    use PersianNormalizer;
    use Toast;
    use WithFileUploads;

    public $file;

    public $importResults = null;

    public $previewData = [];

    public $showPreview = false;

    public $importConfirmed = false;

    public $selectedAction = 'update'; // 'update', 'create', 'skip'

    public $compareKey = 'pc_name'; // 'pc_name', 'mac', 'both'

    public $importStats = [
        'total' => 0,
        'new' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'errors' => 0,
    ];

    public bool $showHelpModal = false;

    /**
     * #1194: the token that identifies the queued import to the browser. Set
     * when the job is dispatched, cleared once the result has been read.
     */
    public ?string $statusToken = null;

    /**
     * #1194: an import is already running for this user — a second click
     * would queue the same sheet twice and both jobs would compare against
     * the pre-import state.
     */
    public bool $importRunning = false;

    protected $rules = [
        'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // max 10MB
    ];

    protected $messages = [
        'file.required' => 'لطفاً یک فایل اکسل انتخاب کنید.',
        'file.mimes' => 'فرمت فایل باید xlsx، xls یا csv باشد.',
        'file.max' => 'حجم فایل نباید بیشتر از ۱۰ مگابایت باشد.',
    ];

    public function mount(): void
    {
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->reset(['file', 'importResults', 'previewData', 'showPreview', 'importConfirmed', 'importStats']);
        $this->importStats = [
            'total' => 0,
            'new' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ];
    }

    public function importPreview(): void
    {
        // لایه‌ی دوم مجوز (ایشو #814 / الگوی #805): همان مجوزِ گیتِ مسیر
        // (`manage_hardware`) باید داخل کامپوننت هم اعمال شود.
        $this->authorize('manage_hardware');
        $this->validate();

        // ایشو #839 (Plan 50): حساب بدون واحد، `accessibleUnitIds()` خالی
        // می‌گیرد — که خروجی معتبر است، نه «بدون محدودیت». ایمپورت سخت‌افزار
        // برای چنین حسابی معنا ندارد و صریح رد می‌شود (دفاع در عمق؛ کلاس
        // `HardwareImport` هم جداگانه fail-closed است).
        if (! $this->hasImportScope()) {
            return;
        }

        try {
            $import = new HardwareImport;
            $import->setCompareKey($this->compareKey);
            $import->setAccessibleUnitIds(app(AccessService::class)->accessibleUnitIds());

            Excel::import($import, $this->file->getRealPath());

            $results = $import->getImportResults();

            $this->previewData = $results['preview'] ?? [];
            $this->importStats = [
                'total' => count($this->previewData),
                'new' => count(array_filter($this->previewData, fn ($r) => $r['status'] === 'create')),
                'updated' => count(array_filter($this->previewData, fn ($r) => $r['status'] === 'update')),
                'unchanged' => count(array_filter($this->previewData, fn ($r) => $r['status'] === 'unchanged')),
                'errors' => count($results['errors'] ?? []),
            ];

            $this->showPreview = true;
            $this->importResults = $results;

            $this->success('پیش‌نمایش ایمپورت آماده شد. تغییرات را بررسی و تایید کنید.', 'موفقیت');
        } catch (\Exception $e) {
            $this->error('خطا در پردازش فایل: '.$e->getMessage(), 'خطا');
        }
    }

    public function confirmImport(): void
    {
        $this->authorize('manage_hardware');

        if (! $this->importResults) {
            $this->error('داده‌ای برای ایمپورت وجود ندارد.', 'خطا');

            return;
        }

        // #1194: one import per user at a time. A double click queued the
        // same sheet twice, and both jobs compared against the pre-import
        // state, so the second one created rows the first had already
        // created. The claim is atomic, so exactly one click wins and the
        // other is told what happened.
        if ($this->importRunning) {
            $this->error('یک ایمپورت در حال انجام است. تا پایان آن، ایمپورت دیگری شروع نمی‌شود.', 'خطا');

            return;
        }

        // ایشو #839 (Plan 50): همان امتناعِ اسکوپ خالی در مسیر تایید.
        if (! $this->hasImportScope()) {
            return;
        }

        try {
            // #1167: the confirmed write is queued. Livewire's temp upload does
            // not survive the request, so persist it first and hand the stable
            // path to the job (the job deletes it after a successful run).
            $path = $this->file?->store('imports');

            if (! $path) {
                $this->error('ذخیره فایل ایمپورت ممکن نشد. لطفاً دوباره تلاش کنید.', 'خطا');

                return;
            }

            $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();

            // #1194: the token links this dispatch to the browser, so the
            // parent table is refreshed once the rows actually exist.
            $token = ImportStatus::token((int) Auth::id());

            // #1194: take the user's single import slot before queueing.
            // `Cache::add` is atomic, so a double click cannot queue the
            // sheet twice — the second click loses the claim and is told
            // that an import is already running.
            if (! ImportStatus::claim((int) Auth::id(), $token)) {
                $this->error('یک ایمپورت در حال انجام است. تا پایان آن، ایمپورت دیگری شروع نمی‌شود.', 'خطا');

                return;
            }

            ImportHardwareJob::dispatch(
                path: $path,
                compareKey: $this->compareKey,
                selectedActions: $this->getSelectedActions(),
                accessibleUnitIds: $accessibleUnitIds,
                userId: Auth::id(),
                source: 'import',
                ip: request()->ip(),
                userAgent: request()->userAgent(),
                statusToken: $token,
            );

            $this->statusToken = $token;
            $this->importRunning = true;

            // The queue is asynchronous: report the state honestly instead of
            // claiming the rows were written before a worker has run.
            $this->success('ایمپورت در حال انجام است. نتیجه پس از تکمیل پردازش اعمال می‌شود.', 'موفقیت');

            $this->resetForm();
            // #1194: `hardware-imported` is NOT dispatched here. The worker has
            // not run yet, so the parent table would refresh before a single
            // row existed and the imported devices stayed invisible until a
            // manual reload. It is dispatched from `checkImportStatus()`, once
            // the job has published its result.
        } catch (\Exception $e) {
            // #1194: the job was never queued, so nothing will release the
            // claim — free the slot here.
            if (isset($token)) {
                ImportStatus::release((int) Auth::id());
            }

            $this->error('خطا در انجام ایمپورت: '.$e->getMessage(), 'خطا');
        }
    }

    /**
     * #1194: poll the queued import for its result.
     *
     * There is no websocket in this app, so the job publishes its outcome to
     * the cache and this method reads it. Once the result is there, the
     * parent component is notified — at that point the rows really exist.
     */
    public function checkImportStatus(): void
    {
        if ($this->statusToken === null) {
            return;
        }

        $result = ImportStatus::read((int) Auth::id(), $this->statusToken);

        if ($result === null) {
            // The worker has not finished yet — keep polling.
            return;
        }

        ImportStatus::forget((int) Auth::id(), $this->statusToken);
        $this->statusToken = null;
        $this->importRunning = false;

        if (($result['state'] ?? null) === 'failed') {
            $this->error('ایمپورت ناموفق بود: '.($result['error'] ?? 'خطای نامشخص'), 'خطا');

            return;
        }

        $this->importStats = [
            'total' => (int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0) + (int) ($result['skipped'] ?? 0),
            'new' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'unchanged' => (int) ($result['skipped'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
        ];

        $this->success(sprintf(
            'ایمپورت تکمیل شد: %d ایجاد، %d به‌روزرسانی، %d بدون تغییر، %d خطا.',
            $this->importStats['new'],
            $this->importStats['updated'],
            $this->importStats['unchanged'],
            $this->importStats['errors'],
        ), 'موفقیت');

        // #1194: per-row errors were surfaced nowhere — the browser only ever
        // saw the "in progress" toast. Show the first few of them.
        $errorMessages = $result['error_messages'] ?? [];

        if (is_array($errorMessages) && $errorMessages !== []) {
            $this->error(implode(' | ', array_slice($errorMessages, 0, 3)), 'خطاهای ایمپورت');
        }

        $this->dispatch('hardware-imported'); // Notify parent component
    }

    public function cancelImport(): void
    {
        $this->resetForm();
    }

    public function updatedCompareKey($value): void
    {
        if ($this->showPreview && $this->importResults) {
            $this->importPreview(); // Re-process with new compare key
        }
    }

    /**
     * ایشو #839 (Plan 50): آیا این حساب اصلاً واحدی برای ایمپورت دارد؟
     *
     * `[]` یک خروجی *معتبر* از `AccessService` است — نه «محدودیتی ندارد».
     * اسکوپ خالی باید صریح رد شود، نه اینکه ایمپورت کل سازمان را شروع کند.
     */
    private function hasImportScope(): bool
    {
        if (app(AccessService::class)->accessibleUnitIds() !== []) {
            return true;
        }

        $this->error('حساب شما به هیچ واحدی تخصیص نیافته است؛ ایمپورت ممکن نیست.', 'خطا');

        return false;
    }

    private function getSelectedActions(): array
    {
        $actions = [];
        foreach ($this->previewData as $index => $row) {
            // For create status, there's no 'id' yet, so check for pc_name or n_code
            if (isset($row['id']) || isset($row['pc_name']) || isset($row['n_code'])) {
                // Match the format used in applySelectedAction: row_{rowNumber}
                // rowNumber = index + 2 (header row + 1-indexed)
                // Use row status as default action: create->create, update->update, unchanged/skip/error->skip
                $defaultAction = match ($row['status'] ?? 'create') {
                    'create' => 'create',
                    'update' => 'update',
                    default => 'skip',
                };
                $actions['row_'.($index + 2)] = $row['selected_action'] ?? $defaultAction;
            }
        }

        return $actions;
    }
};
?>

<div class="card w-full bg-base-100 shadow-xl">
    {{-- #1194: while an import is queued, poll for its result. The job runs
         in a worker and publishes its outcome to the cache; the poll reads it
         and refreshes the parent table only once the rows really exist. The
         element is removed when the import ends, which stops the polling. --}}
    @if ($importRunning)
        <div wire:poll.3s="checkImportStatus" wire:key="import-status-poll" class="hidden"></div>
    @endif

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
            <x-icon name="o-information-circle" class="w-5 h-5" />
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

                <x-button wire:click="importPreview" class="btn-primary w-full max-w-xl" spinner="wire:loading">
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
                                <tr class="{{ $row['status'] === 'create' ? 'bg-success/10' : ($row['status'] === 'update' ? 'bg-warning/10' : 'bg-base-100') }}">
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <select
                                            wire:model="previewData.{{ $index }}.selected_action"
                                            class="select select-sm w-full max-w-xs"
                                            @if($row['status'] === 'skip') disabled @endif
                                        >
                                            <option value="create" @if($row['status'] === 'create') selected @endif>ایجاد جدید</option>
                                            <option value="update" @if($row['status'] === 'update') selected @endif>بروزرسانی</option>
                                            <option value="skip" @if($row['status'] === 'skip') selected @endif>نادیده بگیر</option>
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
                        <x-icon name="o-x-mark" class="w-5 h-5" />
                        انصراف
                    </x-button>
                    <x-button wire:click="confirmImport" class="btn-primary" spinner="wire:loading">
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