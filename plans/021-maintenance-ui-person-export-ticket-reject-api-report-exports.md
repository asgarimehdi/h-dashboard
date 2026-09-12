# Plan 021: Add Maintenance UI + Person Export + Ticket Reject API + Report Exports

**Category:** direction | **Effort:** M/L | **Risk:** LOW | **Priority:** P3 | **Depends on:** none

---
## ⚠️ TL;DR فارسی

**مشکل:** ۴ فیچر ناقص: UI نگهداری، export پرسنل، API reject، export گزارش.

**ریسک:** 🟢 کم

---

## Problem

Four independent gaps prevent users from completing common workflows:

1. **Maintenance schedules have no UI.** `MaintenanceSchedule` model and `GenerateDueMaintenance` command exist (scheduled daily at 03:00), but there are zero Livewire views or routes for creating/viewing/editing/deleting schedules. The feature is effectively hidden.
2. **No Person export.** `PersonImport` exists at `app/Imports/PersonImport.php` and is wired to the kargozini import page, but there is no corresponding `PersonExport`. Hardware has both `HardwareExport` and `HardwareExportController` — persons should follow the same pattern.
3. **Ticket reject missing from API.** The Livewire inbox (`⚡inbox.blade.php:481-505`) implements `rejectTicket()` locally with a DB transaction, but `routes/api.php` has no `POST /api/tickets/{ticket}/reject` endpoint. Mobile users cannot reject tickets.
4. **Report pages have no data export.** Five report views (advanced, todos, persons, units, map-no-boundary) render Highcharts charts and HTML tables, but there is no button to download the underlying data as Excel.

---

## Change 1: Maintenance Schedule Livewire CRUD

### Files to create
- `app/Http/Livewire/Kargozini/MaintenanceSchedule.php` — Livewire component
- `resources/views/livewire/kargozini/maintenance-schedule.blade.php` — view

### Files to modify
- `routes/web.php` — add route inside the `kargozini` middleware group (line ~77, after the persons import route)

### Implementation

**Livewire component** (`app/Http/Livewire/Kargozini/MaintenanceSchedule.php`):
```
namespace App\Http\Livewire\Kargozini;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\MaintenanceSchedule;
use App\Models\Unit;
use App\Services\AccessService;
use Mary\Traits\Toast;

class MaintenanceSchedule extends Component
{
    use WithPagination, Toast;

    public bool $modal = false;
    public ?int $editingId = null;
    public string $title = '';
    public string $frequency = 'monthly';
    public int $recurrence_interval = 1;
    public ?int $unit_id = null;

    // Properties for display
    public string $search = '';
    public int $perPage = 15;

    public function render() { ... }

    public function with(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $units = Unit::whereIn('id', $accessibleIds)->pluck('name', 'id');
        $schedules = MaintenanceSchedule::query()
            ->whereIn('unit_id', $accessibleIds)
            ->when($this->search, fn($q) => $q->where('title', 'LIKE', "%{$this->search}%"))
            ->with('unit:id,name')
            ->orderByDesc('id')
            ->paginate($this->perPage);

        return compact('schedules', 'units');
    }

    // CRUD: openModal, save, edit, delete — standard MaryUI modal pattern
    // save() uses Todo::updateOrCreate pattern; recalculates next_due_at
}
```

**View** (`resources/views/livewire/kargozini/maintenance-schedule.blade.php`):
- Header with title "زمان‌بندی تعمیر و نگهداری"
- Search bar + "جدید" button
- Table: ID, عنوان, واحد, فرکانس, فاصله تکرار, سررسید بعدی, آخرین اجرا, actions
- Modal with form fields: title (input), unit (select from accessible), frequency (select: daily/weekly/monthly), recurrence_interval (number)
- Persian labels throughout, RTL direction

**Route** (add in `routes/web.php` inside the `kargozini` middleware group):
```php
Route::livewire('/kargozini/maintenance', 'kargozini.maintenance-schedule')
    ->name('kargozini.maintenance');
```

### Verification
- `php artisan route:list --path=kargozini/maintenance` shows the new route
- Manual: navigate to `/kargozini/maintenance`, create/edit/delete schedules
- Confirm `GenerateDueMaintenance` command still picks up new schedules

---

## Change 2: Person Export

### Files to create
- `app/Exports/PersonExport.php` — Maatwebsite Excel export class
- `app/Http/Controllers/Api/PersonExportController.php` — controller

### Files to modify
- `routes/web.php` — add export route
- `routes/api.php` — add API export route

### Implementation

**PersonExport** — follow `HardwareExport` pattern exactly:

```php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\{
    FromCollection, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping, WithTitle
};
use Morilog\Jalalian\Jalalian;

class PersonExport implements FromCollection, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping, WithTitle
{
    protected $query;
    protected array $columns;
    protected int $lastId = 0;
    protected int $chunkSize = 500;

    protected array $columnDefs = [
        'n_code'    => ['label' => 'کد ملی'],
        'f_name'    => ['label' => 'نام'],
        'l_name'    => ['label' => 'نام خانوادگی'],
        'tahsil'    => ['label' => 'تحصیلات'],
        'estekhdam' => ['label' => 'نوع استخدام'],
        'radif'     => ['label' => 'ردیف سازمانی'],
        'semat'     => ['label' => 'سمت'],
        'unit_name' => ['label' => 'واحد'],
        'status'    => ['label' => 'وضعیت'],
    ];

    // Constructor, collection(), chunkCollection(), chunkSize(),
    // headings(), map(), resolveValue(), title() — same pattern as HardwareExport

    protected function resolveValue($person, string $key): mixed
    {
        return match ($key) {
            'tahsil'    => $person->tahsil?->name ?? '-',
            'estekhdam' => $person->estekhdam?->name ?? '-',
            'radif'     => $person->radif?->name ?? '-',
            'semat'     => $person->semat?->name ?? '-',
            'unit_name' => $person->unit?->name ?? '-',
            default     => $person->{$key} ?? '-',
        };
    }

    public function title(): string { return 'شناسنامه پرسنل'; }
}
```

**PersonExportController** — follow `HardwareExportController` pattern:
```php
class PersonExportController extends Controller
{
    public function export(UnitScopedRequest $request)
    {
        $columns = array_filter(explode(',', $request->input('columns', '')));
        if (empty($columns)) $columns = ['n_code', 'f_name', 'l_name'];

        $accessibleIds = $request->accessibleIds();
        $query = Person::with(['tahsil', 'estekhdam', 'radif', 'semat', 'unit']);

        if (empty($accessibleIds)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('u_id', $accessibleIds);
        }

        // Apply filters: search, unit, tahsil, estekhdam, semat, status
        // (same PersianNormalizer pattern as HardwareExportController)

        $filename = 'persons-' . now()->format('Ymd-His');
        return Excel::download(new PersonExport($query, $columns), "{$filename}.xlsx");
    }
}
```

**Routes:**
```php
// web.php — inside kargozini middleware group
Route::get('/kargozini/persons/export', [PersonExportController::class, 'export'])
    ->name('kargozini.persons.export');

// api.php — inside persons prefix group
Route::get('/persons/export', [PersonExportController::class, 'export']);
```

### Verification
- `php artisan route:list --path=kargozini/persons/export` visible
- Download test: `GET /kargozini/persons/export` returns .xlsx with correct columns
- Confirm accessible units scoping works

---

## Change 3: Ticket Reject API Endpoint

### Files to modify
- `routes/api.php` — add reject route
- `app/Http/Controllers/Api/TicketController.php` — add `reject()` method

### Implementation

**Route** (add after the `complete` route, line ~107 in `routes/api.php`):
```php
Route::post('/tickets/{ticket}/reject', [TicketController::class, 'reject'])
    ->middleware('permission:manage_unit_tickets');
```

**Controller method** — mirror the Livewire `rejectTicket()` logic from `⚡inbox.blade.php:481-505`:
```php
public function reject(UnitScopedRequest $request, Ticket $ticket): JsonResponse
{
    $result = $request->assertAccessibleUnit($ticket->unit_id);
    if ($result !== true) {
        return $result;
    }

    // Only the ticket's unit owner can reject
    if ($ticket->unit_id !== $request->user()->person?->u_id) {
        return response()->json(['message' => 'Only the owning unit can reject this ticket.'], 403);
    }

    DB::transaction(function () use ($ticket, $request) {
        $ticket->update([
            'status' => 'rejected',
            'current_assignee_id' => $request->user()->id,
        ]);

        $ticket->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'rejected',
            'description' => 'تیکت توسط واحد '
                . ($request->user()->person?->unit?->name ?? 'بدون واحد') . ' رد شد.',
        ]);
    });

    return response()->json([
        'success' => true,
        'data' => $ticket->fresh(),
    ]);
}
```

### Verification
- `php artisan route:list --path=tickets/*/reject` shows the new route
- Test: `POST /api/tickets/{id}/reject` with valid token → status changes to `rejected`
- Test: non-owning unit → 403

---

## Change 4: Report Excel Export Buttons

### Files to modify
- `resources/views/livewire/reports/advanced.blade.php` — add export button
- `resources/views/livewire/reports/todos.blade.php` — add export button
- `resources/views/livewire/reports/persons.blade.php` — add export button
- `resources/views/livewire/reports/units.blade.php` — add export button

### Files to create
- `app/Exports/ReportExport.php` — generic report export (accepts data array + headers)

### Implementation

**Generic ReportExport** class:
```php
class ReportExport implements FromCollection, WithHeadings, WithTitle
{
    protected $data;
    protected $headers;
    protected $sheetTitle;

    public function __construct(array $data, array $headers, string $sheetTitle) { ... }
    public function collection(): Collection { return collect($this->data); }
    public function headings(): array { return $this->headers; }
    public function title(): string { return $this->sheetTitle; }
}
```

**Add export method to each report Livewire component.** For example, in the advanced report:
```php
public function export(): \Symfony\Component\HttpFoundation\BinaryFileResponse
{
    $data = $this->reportData['items'] ?? [];
    // Map to flat arrays for Excel
    $headers = match($this->reportType) {
        'tickets' => ['کد', 'موضوع', 'وضعیت', 'اولویت', 'تاریخ ایجاد', ' واحد'],
        'todos'   => ['عنوان', 'واحد', 'شروع', 'پایان', 'وضعیت'],
        'persons' => ['کد ملی', 'نام', 'نام خانوادگی', 'واحد', 'سمت'],
    };
    // Transform $data to flat rows...
    $filename = "report-{$this->reportType}-" . now()->format('Ymd-His');
    return Excel::download(new ReportExport($rows, $headers, "گزارش"), "{$filename}.xlsx");
}
```

**Add button in each report view header** (inside `<x-slot:actions>`):
```blade
<x-button icon="o-arrow-down-tray" label="دانلود اکسل" class="btn-outline btn-sm"
    wire:click="export" spinner="export" />
```

### Verification
- Each report page shows "دانلود اکسل" button
- Click downloads .xlsx with filtered data matching current view
- Large datasets handled via chunked export in ReportExport

---

## Verification Checklist

- [ ] `php artisan route:list --path=kargozini/maintenance` — new route visible
- [ ] `php artisan route:list --path=kargozini/persons/export` — new route visible
- [ ] `php artisan route:list --path=tickets/*/reject` — new route visible
- [ ] Maintenance schedule CRUD works end-to-end
- [ ] Person export downloads .xlsx with correct columns and org scoping
- [ ] Ticket reject via API matches Livewire behavior
- [ ] Report export buttons download correct filtered data
- [ ] `composer test` passes (or `php artisan test`)
