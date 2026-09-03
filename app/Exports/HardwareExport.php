<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Morilog\Jalali\Jalalian;

class HardwareExport implements FromCollection, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping, WithTitle
{
    protected Builder $query;

    protected array $columns;

    protected int $lastId = 0;

    protected int $chunkSize = 500;

    /**
     * Column definitions: key => ['label' => Persian, 'accessor' => callable|null]
     */
    protected array $columnDefs = [
        'n_code' => ['label' => 'کد ملی'],
        'pc_name' => ['label' => 'نام دستگاه'],
        'type' => ['label' => 'نوع'],
        'os' => ['label' => 'سیستم عامل'],
        'ip_valid' => ['label' => 'IP عمومی'],
        'ip_local' => ['label' => 'IP محلی'],
        'mac' => ['label' => 'MAC'],
        'net_type' => ['label' => 'نوع اتصال'],
        'switch' => ['label' => 'سوئیچ'],
        'port' => ['label' => 'پورت'],
        'vlan' => ['label' => 'VLAN'],
        'motherboard' => ['label' => 'مادربورد'],
        'cpu' => ['label' => 'CPU'],
        'ram' => ['label' => 'RAM'],
        'hdd' => ['label' => 'HDD/SSD'],
        'shutdown' => ['label' => 'وضعیت روشن/خاموش'],
        'mark' => ['label' => 'علامت'],
        'comments' => ['label' => 'توضیحات'],
        'clean_at' => ['label' => 'تاریخ نظافت'],
        'person_name' => ['label' => 'صاحب'],
        'unit_name' => ['label' => 'واحد'],
        'status' => ['label' => 'وضعیت'],
    ];

    public function __construct(Builder $query, array $columns)
    {
        $this->query = $query;
        $this->columns = array_intersect($columns, array_keys($this->columnDefs));
    }

    /**
     * Fallback for small datasets (used by FromCollection).
     */
    public function collection(): Collection
    {
        return $this->query->get();
    }

    /**
     * Chunked export for large datasets — processes records in batches of $chunkSize
     * to avoid loading the entire result set into memory at once.
     */
    public function chunkCollection(): Collection
    {
        $chunk = $this->query
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

    public function headings(): array
    {
        return array_map(
            fn ($key) => $this->columnDefs[$key]['label'],
            $this->columns
        );
    }

    public function map($hardware): array
    {
        return array_map(
            fn ($key) => $this->resolveValue($hardware, $key),
            $this->columns
        );
    }

    protected function resolveValue($hardware, string $key): mixed
    {
        return match ($key) {
            'person_name' => $hardware->person
                ? trim($hardware->person->f_name.' '.$hardware->person->l_name)
                : '-',
            'unit_name' => $hardware->person?->unit?->name ?? '-',
            'shutdown' => $hardware->shutdown ? 'روشن' : 'خاموش',
            'mark' => $hardware->mark ? 'علامت‌دار' : '-',
            'clean_at' => $hardware->clean_at
                ? Jalalian::fromCarbon($hardware->clean_at)->format('Y/m/d')
                : '-',
            'comments' => $hardware->comments ?? '-',
            'status' => $hardware->mark
                ? 'علامت'
                : ($hardware->shutdown ? 'فعال' : 'خاموش'),
            default => $hardware->{$key} ?? '-',
        };
    }

    public function title(): string
    {
        return 'شناسنامه سخت‌افزار';
    }
}
