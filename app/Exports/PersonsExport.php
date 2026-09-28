<?php

namespace App\Exports;

use App\Models\Person;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Morilog\Jalali\Jalalian;

/**
 * One row per person (flat table), mirroring the personnel list columns so the
 * sheet is readable on its own. Lookup labels (سمت، تحصیلات، …) are resolved
 * from relations the controller eager-loads, so mapping a row never queries.
 */
class PersonsExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    /**
     * Column definitions: key => ['label' => Persian]
     *
     * @var array<string, array{label: string}>
     */
    protected array $columnDefs = [
        'n_code' => ['label' => 'کد ملی'],
        'f_name' => ['label' => 'نام'],
        'l_name' => ['label' => 'نام خانوادگی'],
        'full_name' => ['label' => 'نام کامل'],
        'semat' => ['label' => 'سمت'],
        'tahsil' => ['label' => 'تحصیلات'],
        'estekhdam' => ['label' => 'نوع استخدام'],
        'radif' => ['label' => 'ردیف سازمانی'],
        'unit' => ['label' => 'واحد سازمانی'],
        'hire_date' => ['label' => 'تاریخ استخدام'],
        'status' => ['label' => 'وضعیت'],
    ];

    /** @var array<int, string> */
    protected array $columns = [
        'n_code', 'f_name', 'l_name', 'full_name', 'semat', 'tahsil',
        'estekhdam', 'radif', 'unit', 'hire_date', 'status',
    ];

    /** @var Collection<int, Person> */
    protected Collection $persons;

    /** @param  Collection<int, Person>  $persons */
    public function __construct(Collection $persons)
    {
        $this->persons = $persons;
    }

    /**
     * @return Collection<int, Person>
     */
    public function collection(): Collection
    {
        return $this->persons;
    }

    public function headings(): array
    {
        return array_map(
            fn (string $key) => $this->columnDefs[$key]['label'],
            $this->columns
        );
    }

    /**
     * @param  Person  $person
     */
    public function map($person): array
    {
        return array_map(
            fn (string $key) => $this->resolveValue($person, $key),
            $this->columns
        );
    }

    protected function resolveValue(Person $person, string $key): mixed
    {
        return match ($key) {
            'full_name' => $this->resolveFullName($person),
            'semat' => $person->semat->name ?? '-',
            'tahsil' => $person->tahsil->name ?? '-',
            'estekhdam' => $person->estekhdam->name ?? '-',
            'radif' => $person->radif->name ?? '-',
            'unit' => $person->unit->name ?? '-',
            'hire_date' => $this->resolveHireDate($person),
            'status' => $this->resolveStatus($person),
            default => (string) ($person->{$key} ?? '-'),
        };
    }

    /**
     * `Person::$name` falls back to «—» when both name parts are blank, which
     * is a UI affordance, not a name. The sheet gets a dash instead.
     */
    protected function resolveFullName(Person $person): string
    {
        $name = trim("{$person->f_name} {$person->l_name}");

        return $name !== '' ? $name : '-';
    }

    protected function resolveHireDate(Person $person): string
    {
        $date = $person->hire_date;

        return $date !== null ? Jalalian::fromCarbon($date)->format('Y/m/d') : '-';
    }

    /**
     * `persons.status` is a plain string column (active | inactive | retired).
     * An empty or unknown value reports the absence, never a guess.
     */
    protected function resolveStatus(Person $person): string
    {
        return match ($person->status) {
            'active' => 'فعال',
            'inactive' => 'غیرفعال',
            'retired' => 'بازنشسته',
            default => '-',
        };
    }

    public function title(): string
    {
        return 'پرسنل';
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
