<?php

namespace App\Livewire\Hardware\ImportHardware;

use App\Imports\HardwareImport;
use App\Models\Hardware;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use Mary\Traits\Toast;

class ImportHardware extends Component
{
    use Toast;
    use WithFileUploads;
    use PersianNormalizer;

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
        $this->validate();

        try {
            $import = new HardwareImport();
            $import->setCompareKey($this->compareKey);
            $import->setAccessibleUnitIds(app(AccessService::class)->accessibleUnitIds());

            Excel::import($import, $this->file->getRealPath());

            $results = $import->getImportResults();

            $this->previewData = $results['preview'] ?? [];
            $this->importStats = [
                'total' => count($this->previewData),
                'new' => count(array_filter($this->previewData, fn($r) => $r['status'] === 'create')),
                'updated' => count(array_filter($this->previewData, fn($r) => $r['status'] === 'update')),
                'unchanged' => count(array_filter($this->previewData, fn($r) => $r['status'] === 'unchanged')),
                'errors' => count($results['errors'] ?? []),
            ];

            $this->showPreview = true;
            $this->importResults = $results;

            $this->success('پیش‌نمایش ایمپورت آماده شد. تغییرات را بررسی و تایید کنید.', 'موفقیت');
        } catch (\Exception $e) {
            $this->error('خطا در پردازش فایل: ' . $e->getMessage(), 'خطا');
        }
    }

    public function confirmImport(): void
    {
        if (!$this->importResults) {
            $this->error('داده‌ای برای ایمپورت وجود ندارد.', 'خطا');
            return;
        }

        try {
            $import = new HardwareImport();
            $import->setCompareKey($this->compareKey);
            $import->setAccessibleUnitIds(app(AccessService::class)->accessibleUnitIds());
            $import->setSelectedActions($this->getSelectedActions());

            Excel::import($import, $this->file->getRealPath());

            $results = $import->getImportResults();

            $this->success(
                "ایمپورت با موفقیت انجام شد. جدید: {$results['created']}, بروزرسانی: {$results['updated']}, خطا: {$results['errors']}",
                'موفقیت'
            );

            $this->resetForm();
            $this->dispatch('hardware-imported'); // Notify parent component
        } catch (\Exception $e) {
            $this->error('خطا در انجام ایمپورت: ' . $e->getMessage(), 'خطا');
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

    private function getSelectedActions(): array
    {
        $actions = [];
        foreach ($this->previewData as $index => $row) {
            // For create status, there's no 'id' yet, so check for pc_name or n_code
            if (isset($row['id']) || isset($row['pc_name']) || isset($row['n_code'])) {
                // Match the format used in applySelectedAction: row_{rowNumber}
                // rowNumber = index + 2 (header row + 1-indexed)
                // Use row status as default action: create->create, update->update, unchanged/skip/error->skip
                $defaultAction = match($row['status'] ?? 'create') {
                    'create' => 'create',
                    'update' => 'update',
                    default => 'skip',
                };
                $actions["row_" . ($index + 2)] = $row['selected_action'] ?? $defaultAction;
            }
        }
        return $actions;
    }

    public function render()
    {
        return view('livewire.hardware.import-hardware.import-hardware');
    }
}