<?php

namespace App\Exports;

use App\Models\Unit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * One row per unit (flat table). The parent/child relationship is expressed by
 * the «مسیر کامل» breadcrumb plus the «سطح» depth, so the sheet stays sortable
 * and filterable in Excel instead of becoming a ragged column-per-level grid.
 */
class UnitsExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    /**
     * Column definitions: key => ['label' => Persian]
     *
     * @var array<string, array{label: string}>
     */
    protected array $columnDefs = [
        'id' => ['label' => 'شناسه'],
        'name' => ['label' => 'نام واحد'],
        'unit_type' => ['label' => 'نوع واحد'],
        'parent_name' => ['label' => 'والد مستقیم'],
        'full_path' => ['label' => 'مسیر کامل'],
        'depth' => ['label' => 'سطح'],
        'status' => ['label' => 'وضعیت'],
    ];

    /** @var array<int, string> */
    protected array $columns = ['id', 'name', 'unit_type', 'parent_name', 'full_path', 'depth', 'status'];

    /** @var Collection<int, Unit> */
    protected Collection $units;

    /**
     * Pre-resolved hierarchy metadata per unit id, built by the controller in
     * one pass so mapping a row never walks parents (and never N+1s).
     *
     * @var array<int, array{path: string, depth: int, parent_name: string}>
     */
    protected array $hierarchy;

    /**
     * @param  Collection<int, Unit>  $units  depth-first ordered (parents before children)
     * @param  array<int, array{path: string, depth: int, parent_name: string}>  $hierarchy
     */
    public function __construct(Collection $units, array $hierarchy)
    {
        $this->units = $units;
        $this->hierarchy = $hierarchy;
    }

    /**
     * @return Collection<int, Unit>
     */
    public function collection(): Collection
    {
        return $this->units;
    }

    public function headings(): array
    {
        return array_map(
            fn (string $key) => $this->columnDefs[$key]['label'],
            $this->columns
        );
    }

    /**
     * @param  Unit  $unit
     */
    public function map($unit): array
    {
        $meta = $this->hierarchy[$unit->id] ?? ['path' => $unit->name, 'depth' => 0, 'parent_name' => ''];

        return array_map(
            fn (string $key) => $this->resolveValue($unit, $key, $meta),
            $this->columns
        );
    }

    /**
     * @param  array{path: string, depth: int, parent_name: string}  $meta
     */
    protected function resolveValue(Unit $unit, string $key, array $meta): mixed
    {
        return match ($key) {
            'unit_type' => $unit->unitType->name ?? '-',
            'parent_name' => $meta['parent_name'] !== '' ? $meta['parent_name'] : '-',
            'full_path' => $meta['path'],
            'depth' => $meta['depth'],
            'status' => $unit->is_active ? 'فعال' : 'غیرفعال',
            default => $unit->{$key} ?? '-',
        };
    }

    public function title(): string
    {
        return 'واحدها';
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            // Persian sheet: first column renders on the right, like the app UI.
            AfterSheet::class => function (AfterSheet $event): void {
                $event->sheet->getDelegate()->setRightToLeft(true);
            },
        ];
    }
}
