<?php
use App\Imports\PersonImport;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use Mary\Traits\Toast;
return new class extends Component
{
    use PersianNormalizer;
    use Toast;
    use WithFileUploads;
    use \Livewire\WithPagination;

    /**
     * Issue #1168: the parsed preview is O(rows) and lives in
     * `ImportPreviewStore` behind this public token. Only the token ships in
     * the Livewire snapshot — never the rows. The token is the same handle the
     * queued confirm job (#1167) will receive next to the stored file path.
     */
    public ?string $previewToken = null;

    /**
     * Sparse overrides only: `row_{n} => action`, where n is the Excel row
     * number (index + 2). Untouched rows keep the status-derived default.
     *
     * @var array<string, string>
     */
    public array $rowActions = [];

    private const PREVIEW_PER_PAGE = 50;

    public $file;

    public $importResults = null;

    public $showPreview = false;

    public $importConfirmed = false;

    public $selectedAction = 'update'; // 'update', 'create', 'skip'

    public $compareKey = 'n_code'; // always n_code for persons

    public bool $showHelpModal = false;

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
        \App\Support\ImportPreviewStore::forget($this->previewToken);

        $this->reset(['file', 'importResults', 'previewToken', 'rowActions', 'showPreview', 'importConfirmed']);
        $this->importResults = null;
        $this->rowActions = [];
        $this->resetPage();
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

            // Issue #1168: the whole parsed result (rows + counters + errors)
            // goes to the server-side store; only its token enters the
            // snapshot. `importResults` keeps the small counters so the rest
            // of the component can tell "a preview exists".
            \App\Support\ImportPreviewStore::forget($this->previewToken);
            $token = (string) \Illuminate\Support\Str::uuid();
            \App\Support\ImportPreviewStore::put($token, $results);

            $this->previewToken = $token;
            $this->rowActions = [];
            $this->importResults = [
                'created' => $results['created'] ?? 0,
                'updated' => $results['updated'] ?? 0,
                'skipped' => $results['skipped'] ?? 0,
            ];

            $this->showPreview = true;
            $this->resetPage();

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

        // ایشو #839 (Plan 50): همان امتناعِ اسکوپ خالی در مسیر تایید —
        // وگرنه وضعیتِ پیش‌نمایشِ یک اسکوپِ قبلی به ایمپورتِ کامل سازمان تبدیل می‌شود.
        if (! $this->hasImportScope()) {
            return;
        }

        // Issue #1168: confirm reads the choices from the cached preview. An
        // expired/unknown token must produce a readable message, not a raw
        // exception from a half-hydrated component.
        if (\App\Support\ImportPreviewStore::get($this->previewToken) === null) {
            $this->error('پیش‌نمایش ایمپورت منقضی شده است؛ لطفاً فایل را دوباره بارگذاری کنید.', 'خطا');
            $this->resetForm();

            return;
        }

        try {
            $import = new PersonImport;
            $import->setCompareKey($this->compareKey);
            $import->setAccessibleUnitIds(app(AccessService::class)->accessibleUnitIds());
            $import->setSelectedActions($this->getSelectedActions());

            Excel::import($import, $this->file->getRealPath());

            $results = $import->getImportResults();

            $this->success(
                // ایشو #872: `errors` یک آرایه است (رکوردهای خطای هر ردیف را
                // حمل می‌کند و بلوک آمار با `json_encode` نمایشش می‌دهد)، نه
                // یک شمارش. درجِ مستقیمش `Array to string conversion` می‌داد،
                // که `HandleExceptions` به `ErrorException` تبدیل می‌کند و
                // `catch (\Exception)` پایین‌تر آن را می‌بلعد — پس `resetForm()`
                // و `dispatch()` هرگز اجرا نمی‌شدند و رویداد refresh والد مرده
                // ماند. همان idiom `count($this->previewErrors)` بالاتر در همین
                // فایل: شمارش، نه درج.
                'ایمپورت با موفقیت انجام شد. جدید: '.$results['created'].', بروزرسانی: '.$results['updated'].', خطا: '.count($results['errors'] ?? []),
                'موفقیت'
            );

            $this->resetForm();
            $this->dispatch('persons-imported'); // Notify parent component
        } catch (\Exception $e) {
            $this->error('خطا در انجام ایمپورت: '.$e->getMessage(), 'خطا');
        }
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tableHeaders(): array
    {
        return [
            ['key' => '_display_number', 'label' => '#', 'class' => 'w-10'],
            ['key' => 'action', 'label' => 'عملیات'],
            ['key' => 'n_code', 'label' => 'کد ملی'],
            ['key' => 'f_name', 'label' => 'نام'],
            ['key' => 'l_name', 'label' => 'نام خانوادگی'],
            ['key' => 't_id', 'label' => 'تحصیلات'],
            ['key' => 'e_id', 'label' => 'نوع استخدام'],
            ['key' => 's_id', 'label' => 'سمت'],
            ['key' => 'r_id', 'label' => 'ردیف'],
            ['key' => 'u_id', 'label' => 'واحد'],
            ['key' => 'changes', 'label' => 'تغییرات'],
        ];
    }

    /**
     * @return array<string, callable(array<string, mixed>): bool>
     */
    public function rowDecorations(): array
    {
        return [
            'bg-success/10' => fn (array $row): bool => ($row['status'] ?? null) === 'create',
            'bg-warning/10' => fn (array $row): bool => ($row['status'] ?? null) === 'update',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previewRows(): array
    {
        return \App\Support\ImportPreviewStore::rows($this->previewToken);
    }

    /**
     * The current window of the cached preview (50 rows), with the absolute
     * Excel row number attached for the per-row control.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, array<string, mixed>>
     */
    #[\Livewire\Attributes\Computed]
    public function previewPage(): \Illuminate\Pagination\LengthAwarePaginator
    {
        $rows = $this->previewRows();
        $perPage = self::PREVIEW_PER_PAGE;
        $lastPage = max(1, (int) ceil(count($rows) / $perPage));
        $page = min(max(1, (int) $this->getPage()), $lastPage);
        $offset = ($page - 1) * $perPage;

        $items = [];

        // Preserve the original indexes: `row_{index+2}` is the key contract
        // `PersonImport::applySelectedAction()` already expects.
        foreach (array_slice($rows, $offset, $perPage, true) as $index => $row) {
            $row['_row_number'] = $index + 2;
            $row['_display_number'] = $index + 1;
            $items[] = $row;
        }

        return new \Illuminate\Pagination\LengthAwarePaginator($items, count($rows), $perPage, $page);
    }

    /**
     * @return array{total: int, new: int, updated: int, unchanged: int, errors: int}
     */
    #[\Livewire\Attributes\Computed]
    public function previewStats(): array
    {
        $stats = ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];

        foreach ($this->previewRows() as $row) {
            $stats['total']++;

            switch ($row['status'] ?? null) {
                case 'create':
                    $stats['new']++;
                    break;
                case 'update':
                    $stats['updated']++;
                    break;
                case 'unchanged':
                    $stats['unchanged']++;
                    break;
            }
        }

        $stats['errors'] = count($this->previewErrors());

        return $stats;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[\Livewire\Attributes\Computed]
    public function previewErrors(): array
    {
        $results = \App\Support\ImportPreviewStore::get($this->previewToken);
        $errors = $results['errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }

    /**
     * The action a row will be imported with: the sparse override if the user
     * touched it, otherwise the same status-derived default as before #1168.
     *
     * @param  array<string, mixed>  $row
     */
    public function rowActionFor(array $row): string
    {
        $key = 'row_'.($row['_row_number'] ?? 0);

        return $this->rowActions[$key] ?? $this->defaultActionFor($row);
    }

    /**
     * Persist one row's decision server-side (sparse map, no snapshot rows).
     */
    public function setRowAction(int $rowNumber, string $action): void
    {
        if (! in_array($action, ['create', 'update', 'skip'], true)) {
            return;
        }

        $row = $this->previewRows()[$rowNumber - 2] ?? null;

        if (! is_array($row) || ! $this->rowCarriesActionKey($row)) {
            return;
        }

        $this->rowActions['row_'.$rowNumber] = $action;
    }

    /**
     * Page-level bulk buttons: only the rows of the current window change.
     */
    public function applyBulkAction(string $action): void
    {
        if (! in_array($action, ['create', 'update', 'skip'], true)) {
            return;
        }

        foreach ($this->previewPage()->items() as $row) {
            if (! $this->rowCarriesActionKey($row)) {
                continue;
            }

            $this->rowActions['row_'.$row['_row_number']] = $action;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function defaultActionFor(array $row): string
    {
        // Use row status as default action: create->create, update->update,
        // unchanged/skip/error->skip.
        return match ($row['status'] ?? 'create') {
            'create' => 'create',
            'update' => 'update',
            default => 'skip',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowCarriesActionKey(array $row): bool
    {
        return isset($row['n_code']);
    }

    /**
     * @return array<string, string>
     */
    private function getSelectedActions(): array
    {
        $actions = [];

        foreach ($this->previewRows() as $index => $row) {
            if (! $this->rowCarriesActionKey($row)) {
                continue;
            }

            // Match the format used in applySelectedAction: row_{rowNumber}
            // rowNumber = index + 2 (header row + 1-indexed).
            $actions['row_'.($index + 2)] = $this->rowActions['row_'.($index + 2)] ?? $this->defaultActionFor($row);
        }

        return $actions;
    }
};
?>

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
                    <h3 class="text-xl font-semibold">پیش‌نمایش تغییرات ({{ $this->previewStats['total'] }} رکورد)</h3>
                    <div class="flex items-center gap-4 text-sm">
                        <span class="badge badge-success gap-1"><x-icon name="o-plus-circle" class="w-4 h-4" /> {{ $this->previewStats['new'] }} جدید</span>
                        <span class="badge badge-warning gap-1"><x-icon name="o-arrow-path" class="w-4 h-4" /> {{ $this->previewStats['updated'] }} بروزرسانی</span>
                        <span class="badge badge-info gap-1"><x-icon name="o-minus-circle" class="w-4 h-4" /> {{ $this->previewStats['unchanged'] }} بدون تغییر</span>
                        @if($this->previewStats['errors'] > 0)
                            <span class="badge badge-error gap-1"><x-icon name="o-x-circle" class="w-4 h-4" /> {{ $this->previewStats['errors'] }} خطا</span>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-base-content/60">عملیات گروهی روی ردیف‌های همین صفحه:</span>
                    <x-button wire:click="applyBulkAction('create')" class="btn-sm btn-success" spinner="wire:loading">
                        ایجاد
                    </x-button>
                    <x-button wire:click="applyBulkAction('update')" class="btn-sm btn-warning" spinner="wire:loading">
                        بروزرسانی
                    </x-button>
                    <x-button wire:click="applyBulkAction('skip')" class="btn-sm btn-ghost" spinner="wire:loading">
                        نادیده بگیر
                    </x-button>
                </div>

                <x-table
                    :headers="$this->tableHeaders()"
                    :rows="$this->previewPage"
                    :row-decoration="$this->rowDecorations()"
                    with-pagination
                >
                    @scope('cell_action', $row)
                        <select
                            wire:change="setRowAction({{ $row['_row_number'] }}, $event.target.value)"
                            class="select select-sm w-full max-w-xs"
                            @if(($row['status'] ?? null) === 'error') disabled @endif
                        >
                            <option value="create" @selected($this->rowActionFor($row) === 'create')>ایجاد جدید</option>
                            <option value="update" @selected($this->rowActionFor($row) === 'update')>بروزرسانی</option>
                            <option value="skip" @selected($this->rowActionFor($row) === 'skip')>نادیده بگیر</option>
                        </select>
                    @endscope

                    @scope('cell_n_code', $row)
                        <span class="font-mono font-medium">{{ $row['data']['n_code'] ?? '-' }}</span>
                    @endscope

                    @scope('cell_f_name', $row)
                        {{ $row['data']['f_name'] ?? '-' }}
                    @endscope

                    @scope('cell_l_name', $row)
                        {{ $row['data']['l_name'] ?? '-' }}
                    @endscope

                    @scope('cell_t_id', $row)
                        {{ $row['data']['t_id'] ?? '-' }}
                    @endscope

                    @scope('cell_e_id', $row)
                        {{ $row['data']['e_id'] ?? '-' }}
                    @endscope

                    @scope('cell_s_id', $row)
                        {{ $row['data']['s_id'] ?? '-' }}
                    @endscope

                    @scope('cell_r_id', $row)
                        {{ $row['data']['r_id'] ?? '-' }}
                    @endscope

                    @scope('cell_u_id', $row)
                        {{ $row['data']['u_id'] ?? '-' }}
                    @endscope

                    @scope('cell_changes', $row)
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
                    @endscope
                </x-table>

                @if($this->previewStats['errors'] > 0)
                    <div class="alert alert-error">
                        <x-icon name="o-alert-triangle" class="w-5 h-5" />
                        <span>{{ $this->previewStats['errors'] }} ردیف با خطای اعتبارسنجی مواجه شده و نادیده گرفته می‌شوند.</span>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-sm underline">مشاهده خطاها</summary>
                            <pre class="mt-2 p-3 bg-base-200 rounded text-xs overflow-auto">{{ json_encode($this->previewErrors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
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