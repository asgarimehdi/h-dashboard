<?php

namespace App\Exports;

use App\Models\HardwareAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Morilog\Jalali\Jalalian;

class HardwareAuditsExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected Builder $query;

    public function __construct(Builder $query)
    {
        $this->query = $query;
    }

    /**
     * @return Collection<int, array>
     */
    public function collection(): Collection
    {
        // Return the raw models; Maatwebsite calls map() per row (WithMapping).
        return $this->query->with('user:id,n_code,name')
            ->latest('created_at')
            ->get();
    }

    /**
     * @var array<int, string>
     */
    public function headings(): array
    {
        return [
            'شناسه',
            'عملیات',
            'منبع',
            'تغییرات',
            'آدرس IP',
            'کاربر آژنت',
            'تاریخ (میلادی)',
            'تاریخ (شمسی)',
            'کاربر (کد ملی)',
            'کاربر (نام)',
        ];
    }

    /**
     * @param mixed $audit
     * @return array<int, mixed>
     */
    public function map($audit): array
    {
        $changesSummary = '';
        if ($audit->changes && is_array($audit->changes)) {
            $changesSummary = implode(' | ', array_map(
                fn($c) => "{$c['field']}: {$c['old']} → {$c['new']}",
                $audit->changes
            ));
        }

        return [
            $audit->id,
            $this->getActionLabel($audit->action),
            $this->getSourceLabel($audit->source),
            $changesSummary,
            $audit->ip_address ?? '',
            $audit->user_agent ?? '',
            $audit->created_at?->toIso8601String() ?? '',
            $audit->created_at
                ? Jalalian::fromCarbon($audit->created_at)->format('Y/m/d H:i:s')
                : '',
            $audit->user?->n_code ?? '',
            $audit->user?->name ?? '',
        ];
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return 'تاریخچه تغییرات سخت‌افزار';
    }

    protected function getActionLabel(string $action): string
    {
        return match ($action) {
            'created' => 'ایجاد',
            'updated' => 'بروزرسانی',
            'deleted' => 'حذف',
            'bulk_mark' => 'علامت‌گذاری گروهی',
            'bulk_delete' => 'حذف گروهی',
            'force_deleted' => 'حذف اجباری',
            'rollback' => 'بازگردانی',
            default => $action,
        };
    }

    protected function getSourceLabel(string $source): string
    {
        return match ($source) {
            'web' => 'وب',
            'api' => 'API (موبایل)',
            'import' => 'ایمپورت',
            'bulk' => 'عملیات گروهی',
            default => $source,
        };
    }
}
