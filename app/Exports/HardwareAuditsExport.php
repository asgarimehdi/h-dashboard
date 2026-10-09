<?php

namespace App\Exports;

use App\Support\ExcelCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Morilog\Jalali\Jalalian;

class HardwareAuditsExport implements FromCollection, WithChunkReading, WithHeadings, WithMapping, WithTitle
{
    protected Builder $query;

    protected int $lastId = 0;

    protected int $chunkSize = 500;

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
        return $this->query->with('user.person:id,n_code,f_name,l_name')
            ->latest('created_at')
            ->get();
    }

    /**
     * Chunked export for large datasets — processes records in batches of $chunkSize
     * to avoid loading the entire result set into memory at once.
     */
    public function chunkCollection(): Collection
    {
        $chunk = $this->query
            ->with('user.person:id,n_code,f_name,l_name')
            ->where('id', '>', $this->lastId)
            ->orderBy('id')
            ->take($this->chunkSize)
            ->get();

        if ($chunk->isNotEmpty()) {
            $this->lastId = $chunk->last()->id;
        }

        return $chunk;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
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
     * @param  mixed  $audit
     * @return array<int, mixed>
     */
    public function map($audit): array
    {
        $changesSummary = '';
        if ($audit->changes && is_array($audit->changes)) {
            $changesSummary = ExcelCell::escape(implode(' | ', array_map(
                fn ($c) => "{$c['field']}: {$c['old']} → {$c['new']}",
                $audit->changes
            )));
        }

        // #886 (CWE-1236): user_agent / changes / user.name are
        // attacker-controlled; escape at map() time (also fixes ?format=csv).
        return [
            $audit->id,
            $this->getActionLabel($audit->action),
            $this->getSourceLabel($audit->source),
            $changesSummary,
            ExcelCell::escape($audit->ip_address ?? ''),
            ExcelCell::escape($audit->user_agent ?? ''),
            $audit->created_at?->toIso8601String() ?? '',
            $audit->created_at
                ? Jalalian::fromCarbon($audit->created_at)->format('Y/m/d H:i:s')
                : '',
            ExcelCell::escape($audit->user?->n_code ?? ''),
            ExcelCell::escape($audit->user?->name ?? ''),
        ];
    }

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
