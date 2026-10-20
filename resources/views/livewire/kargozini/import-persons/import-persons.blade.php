<?php
use App\Imports\PersonImport;
use App\Jobs\ImportPersonsJob;
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

    public $compareKey = 'n_code'; // always n_code for persons

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
        // لایه‌ی دوم مجوز (ایشو #814 / الگوی #805): مسیر فقط `manage_personnel`
        // را می‌پذیرد؛ نوشتن داخل کامپوننت هم باید همان را بخواهد.
        $this->authorize('manage_personnel');
        $this->validate();

        // ایشو #839 (Plan 50): حساب بدون واحد «اسکوپ خالی» دارد و
        // `accessibleUnitIds()` برایش `[]` برمی‌گرداند — که یک خروجی معتبر
        // است، نه «بدون محدودیت». ایمپورت برای چنین حسابی معنا ندارد، پس
        // پیش از هر پردازشی صریح رد می‌شود (لایه‌ی UX + دفاع در عمق؛ خودِ
        // کلاس‌ها هم جداگانه fail-closed هستند).
        if (! $this->hasImportScope()) {
            return;
        }

        try {
            $import = new PersonImport;
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
        $this->authorize('manage_personnel');

        if (! $this->importResults) {
            $this->error('داده‌ای برای ایمپورت وجود ندارد.', 'خطا');

            return;
        }

        // #1194: one import per user at a time. A double click queued the
        // same sheet twice, and both jobs compared against the pre-import
        // state, so the second one created rows the first had already
        // created.
        if ($this->importRunning) {
            $this->error('یک ایمپورت در حال انجام است. تا پایان آن، ایمپورت دیگری شروع نمی‌شود.', 'خطا');

            return;
        }

        // ایشو #839 (Plan 50): همان امتناعِ اسکوپ خالی در مسیر تایید —
        // وگرنه وضعیتِ پیش‌نمایشِ یک اسکوپِ قبلی به ایمپورتِ کامل سازمان تبدیل می‌شود.
        if (! $this->hasImportScope()) {
            return;
        }

        try {
            // #1167: persist the temp upload first; the queued job needs a path
            // that outlives this request and deletes it after a successful run.
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

            ImportPersonsJob::dispatch(
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
            // #1194: `persons-imported` is NOT dispatched here. The worker has
            // not run yet, so the parent table would refresh before a single
            // row existed and the imported personnel stayed invisible until a
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

        $this->dispatch('persons-imported'); // Notify parent component
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
     * `[]` یک خروجی *معتبر* از `AccessService` است (حسابی بدون ردیف
     * `user_units` و بدون `person.u_id` که `ValidateUnitContext` هم عمداً
     * راهش می‌دهد) — نه «محدودیتی ندارد». پس اسکوپ خالی باید صریح رد شود.
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
            if (isset($row['n_code'])) {
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
                ورود اطلاعات پرسنل از فایل اکسل
            </h2>
            <x-help:button section="persons-import" wireModel="showHelpModal" />
        </div>

        <x-help:modal wireModel="showHelpModal" />

        <div class="alert alert-info mb-6">
            <x-icon name="o-information-circle" class="w-5 h-5" />
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
    </div></div>

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