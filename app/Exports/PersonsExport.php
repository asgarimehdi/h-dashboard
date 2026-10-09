<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsJalaliDates;
use App\Models\Person;
use App\Models\Unit;
use App\Services\UnitTreeService;
use App\Support\ExcelCell;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * One row per person (flat table), mirroring the personnel list columns so the
 * sheet is readable on its own. Lookup labels (سمت، تحصیلات، …) are resolved
 * from relations the controller eager-loads, so mapping a row never queries.
 */
class PersonsExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    use FormatsJalaliDates;

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
        'status' => ['label' => 'وضعیت'],
        'birth_date' => ['label' => 'تاریخ تولد'],
        'hire_date' => ['label' => 'تاریخ استخدام'],
    ];

    /** @var array<int, string> */
    protected array $columns = [
        'n_code', 'f_name', 'l_name', 'full_name', 'semat', 'tahsil',
        'estekhdam', 'radif', 'unit', 'status', 'birth_date', 'hire_date',
    ];

    /** @var Collection<int, Person> */
    protected Collection $persons;

    /**
     * Breadcrumb walker from the shared unit tree service (#756) — the same
     * chain the tree UI and the units export resolve their hierarchy with.
     */
    protected UnitTreeService $unitTree;

    /**
     * Every unit keyed by id with `parent` linked in memory, built once on
     * first row so mapping never walks back to the database.
     *
     * @var array<int, Unit>|null
     */
    protected ?array $unitsById = null;

    /** @param  Collection<int, Person>  $persons */
    public function __construct(Collection $persons)
    {
        $this->persons = $persons;
        $this->unitTree = new UnitTreeService;
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
        // #886 (CWE-1236): escape at map() time so '='-leading values bind
        // as text. Only map() also fixes the audits CSV route, which carries
        // no cell-type metadata.
        return array_map(
            fn (string $key) => ExcelCell::escape($this->resolveValue($person, $key)),
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
            'unit' => $this->resolveUnitPath($person),
            'status' => $this->resolveStatus($person),
            'birth_date' => $this->resolveJalaliDate($person->birth_date),
            'hire_date' => $this->resolveJalaliDate($person->hire_date),
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

    /**
     * #890: delegates to the shared formatter so `birth_date` / `hire_date`
     * cannot re-introduce the "one unformattable date 500s the whole export"
     * failure the hardware export had. PersonImport carries neither
     * birth_date nor hire_date as importable columns today, so this is
     * prevention rather than a live bug fix — the guard is here so that if
     * those columns are ever added to the personnel sheet, they inherit it.
     */
    protected function resolveJalaliDate(?Carbon $date): string
    {
        return $this->formatJalaliOrDash($date);
    }

    /**
     * The person's unit as the full breadcrumb — the same contract as the
     * units export's «مسیر کامل» column (#756): every ancestor names the
     * path, joined with ' > ', and ancestors above the caller's scope still
     * appear (the tree page renders those, so nothing new leaks). Walking
     * `UnitTreeService::ancestorChain()` over the in-memory index keeps the
     * guarantee the class docblock makes: mapping a row never queries.
     * A person whose unit is missing or gone reports '-' like every other
     * absent cell in this sheet.
     */
    protected function resolveUnitPath(Person $person): string
    {
        $units = $this->unitsById();
        $unit = $units[(int) $person->u_id] ?? null;

        if (! $unit instanceof Unit) {
            return '-';
        }

        $chain = $this->unitTree->ancestorChain($unit, array_keys($units));

        return $chain->pluck('name')->push($unit->name)->implode(' > ');
    }

    /**
     * All units keyed by id, with each `parent` relation pre-linked in
     * memory. One query per export, not one per row or per hop — the same
     * shape UnitsExportController's `hierarchyMap()` gives the units
     * breadcrumb. The index deliberately covers every unit, not just the
     * caller's scope, so `ancestorChain()` walks to the real root.
     *
     * @return array<int, Unit>
     */
    protected function unitsById(): array
    {
        if ($this->unitsById !== null) {
            return $this->unitsById;
        }

        /** @var array<int, Unit> $units */
        $units = [];

        foreach (Unit::query()->get(['id', 'name', 'parent_id']) as $unit) {
            $units[(int) $unit->id] = $unit;
        }

        foreach ($units as $unit) {
            if ($unit->parent_id !== null) {
                // A parent_id pointing outside the index (impossible under
                // the FK, guarded anyway) ends the chain instead of exploding.
                $unit->setRelation('parent', $units[(int) $unit->parent_id] ?? null);
            }
        }

        return $this->unitsById = $units;
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
