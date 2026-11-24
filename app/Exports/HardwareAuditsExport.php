<?php

namespace App\Exports;

use App\Support\ExcelCell;
use App\Support\HardwareAuditChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Morilog\Jalali\Jalalian;

class HardwareAuditsExport implements FromQuery, WithCustomChunkSize, WithHeadings, WithMapping, WithTitle
{
    protected Builder $query;

    protected int $chunkSize = 500;

    public function __construct(Builder $query, private readonly bool $csv = false)
    {
        $this->query = $query;
    }

    public function query(): Builder
    {
        $ordered = $this->query
            // Eager-load per chunk, so map() resolves labels without N+1.
            ->with('user.person:id,n_code,f_name,l_name')
            // `id` is the tie-breaker: the library chunks with LIMIT/OFFSET
            // and created_at carries second precision, so same-second rows
            // could silently repeat or drop between chunks without it. It
            // keeps the newest-first display order (latest is DESC).
            ->latest('created_at')
            ->orderBy('id');

        // orderBy() forwards through the Eloquent mixin and types as the base
        // query builder; re-pin the Eloquent builder so the hand-off to
        // `Builder::chunk()` keeps its type.
        /** @var Builder<Model> $ordered */
        return $ordered;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * @return array<int, string>
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
        // #927: `changes` is a diff list, but rows written before the fix (and
        // by any future writer that hands over a raw attribute map) decode to
        // something else. Interpolating array offsets into that threw a
        // TypeError out of the mapper and took the whole export down with it,
        // so render whatever shape arrived and degrade instead.
        $lines = HardwareAuditChange::lines($audit->changes);
        $changesSummary = $lines === [] ? '' : $this->cell(implode(' | ', $lines));

        // #886 (CWE-1236) / #1766: user_agent / changes / user.name are
        // attacker-controlled. '=' is escaped at map() time for every format;
        // the other CSV triggers ('+', '-', '@', tab, CR) are escaped only on
        // the CSV path, because xlsx binds them as strings and escaping them
        // there would corrupt values like '-1+2'.
        return [
            $audit->id,
            $this->getActionLabel($audit->action),
            $this->getSourceLabel($audit->source),
            $changesSummary,
            $this->cell($audit->ip_address ?? ''),
            $this->cell($audit->user_agent ?? ''),
            $audit->created_at?->toIso8601String() ?? '',
            $audit->created_at
                ? Jalalian::fromCarbon($audit->created_at)->format('Y/m/d H:i:s')
                : '',
            $this->cell($audit->user?->n_code ?? ''),
            $this->cell($audit->user?->name ?? ''),
        ];
    }

    /**
     * Apply the export-time formula guard to one cell.
     *
     * `escape()` runs first for every format; the remaining CSV triggers are
     * neutralised only when rendering CSV, where a file carries no cell-type
     * metadata (#1766). escape() is idempotent over its own output, so an
     * '='-leading value is never double-prefixed.
     */
    private function cell(mixed $value): mixed
    {
        $value = ExcelCell::escape($value);

        return $this->csv ? ExcelCell::escapeForCsv($value) : $value;
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
