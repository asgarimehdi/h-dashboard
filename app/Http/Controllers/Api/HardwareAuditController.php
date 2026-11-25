<?php

namespace App\Http\Controllers\Api;

use App\Exports\HardwareAuditsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Observers\HardwareAuditObserver;
use App\Rules\PerPage;
use App\Services\HardwareAuditScope;
use App\Support\ExcelCell;
use App\Support\HardwareAuditChange;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Morilog\Jalali\Jalalian;

class HardwareAuditController extends Controller
{
    /**
     * Display a paginated list of audits for a hardware item.
     */
    public function index(UnitScopedRequest $request, Hardware $hardware): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        $query = HardwareAudit::where('hardware_id', $hardware->id)
            ->with('user.person:id,n_code,f_name,l_name')
            ->latest('created_at');

        // Filters
        if ($request->filled('field')) {
            $query->whereJsonContains('changes', ['field' => $request->field]);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        // Issue #894: see UnitController::index(). `min(...)` bounds only the upper
        // side; a negative `per_page` reaches `limit(-1)`, which the query builder
        // drops, so the SQL loses its LIMIT clause. 422 is the contract; the
        // `min()` stays as defence in depth. This endpoint's own cap is 50, so
        // the rule is parametrised to match it.
        $request->validate(['per_page' => ['sometimes', new PerPage(50)]]);
        $perPage = min((int) $request->get('per_page', 20), 50);
        $audits = $query->paginate($perPage);

        return response()->json([
            'data' => $audits->getCollection()->map(fn ($audit) => $this->transformAudit($audit))->all(),
            'meta' => [
                'current_page' => $audits->currentPage(),
                'last_page' => $audits->lastPage(),
                'per_page' => $audits->perPage(),
                'total' => $audits->total(),
            ],
        ]);
    }

    /**
     * Display a single audit record with full diff.
     */
    public function show(UnitScopedRequest $request, Hardware $hardware, HardwareAudit $audit): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        if ($audit->hardware_id !== $hardware->id) {
            return response()->json(['message' => 'Audit not found for this hardware.'], 404);
        }

        $audit->load('user.person:id,n_code,f_name,l_name');

        return response()->json([
            'data' => $this->transformAudit($audit, true),
        ]);
    }

    /**
     * Rollback a specific field to its previous value.
     */
    public function rollback(UnitScopedRequest $request, Hardware $hardware, HardwareAudit $audit): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        if ($audit->hardware_id !== $hardware->id) {
            return response()->json(['message' => 'Audit not found for this hardware.'], 404);
        }

        $allowed = (new Hardware)->getFillable();
        $request->validate([
            'field' => ['required', 'string', Rule::in($allowed)],
        ]);

        $changes = $audit->changes;
        if (! $changes) {
            return response()->json(['message' => 'No changes to rollback.'], 422);
        }

        $fieldChange = null;
        foreach ($changes as $change) {
            if (($change['field'] ?? '') === $request->field) {
                $fieldChange = $change;
                break;
            }
        }

        if (! $fieldChange) {
            return response()->json(['message' => 'Field not found in audit record.'], 422);
        }

        // #1736: a `created` audit has no previous value — the observer stores
        // the '—' sentinel as `old` for every field. Parsing it back yields
        // null, which wipes a nullable column, 500s on a NOT NULL one, and
        // leaves a `rollback` row certifying the wipe. The #1669 currency guard
        // passes exactly when nothing has changed since creation. Guard on the
        // action, not the sentinel: an `updated` row legitimately stores '—'
        // for a null → value transition. Restoring a deleted record is
        // restoreRecord()'s job.
        if ($audit->action === 'created') {
            return response()->json([
                'message' => 'Cannot roll back a "created" audit: there is no previous value to restore.',
            ], 422);
        }

        $oldValue = $fieldChange['old'] ?? '—';
        $newValue = $fieldChange['new'] ?? '—';

        // Parse the old value back to its original type
        $restoredValue = $this->parseValueForRestore($oldValue, $request->field);

        // Verify target unit is accessible when restoring n_code
        if ($request->field === 'n_code' && $restoredValue !== null) {
            $person = Person::where('n_code', $restoredValue)->first();
            if (! $person) {
                return response()->json(['message' => 'Person not found.'], 422);
            }
            $accessibleIds = $request->accessibleIds();
            if (! in_array($person->u_id, $accessibleIds, true)) {
                return response()->json(['message' => 'Cannot restore hardware to a person in an inaccessible unit.'], 403);
            }
        }

        $rollbackChanges = [[
            'field' => $request->field,
            'old' => $newValue,
            'new' => $oldValue,
        ]];

        // #1669: refuse to overwrite a value that moved on after this audit row
        // was written. bulk_delete rows store a sentinel in `new`, not a value.
        $guardApplies = $audit->action !== 'bulk_delete';

        $outcome = DB::transaction(function () use ($hardware, $request, $restoredValue, $newValue, $rollbackChanges, $guardApplies): string {
            $locked = Hardware::query()->whereKey($hardware->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return 'missing';
            }

            if ($guardApplies
                && HardwareAuditChange::storedForm($locked->getAttribute($request->field))
                    !== HardwareAuditChange::storedForm($newValue)) {
                return 'stale';
            }

            $locked->update([$request->field => $restoredValue]);

            // Log the rollback as a new audit entry (same transaction).
            app(HardwareAuditObserver::class)
                ->recordRollbackAudit($locked, $rollbackChanges, $request->user()?->id);

            return 'ok';
        });

        if ($outcome !== 'ok') {
            return response()->json([
                'message' => $outcome === 'stale'
                    ? "Field '{$request->field}' has changed since this audit entry; rollback refused."
                    : 'Hardware record no longer exists.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "فیلد {$request->field} به مقدار قبلی بازگردانده شد.",
            'data' => [
                'field' => $request->field,
                'restored_value' => $oldValue,
            ],
        ]);
    }

    /**
     * Restore a fully-deleted hardware record from its 'created' audit entry.
     *
     * Looks up the HardwareAudit row (which survives hard deletes) and
     * recreates the Hardware with the original field values.
     */
    public function restoreRecord(UnitScopedRequest $request, HardwareAudit $audit): JsonResponse
    {
        if ($audit->action !== 'created') {
            return response()->json(['message' => 'Only "created" audits can be used to restore a record.'], 422);
        }

        // Verify the hardware was actually deleted. Use a direct query so this
        // works whether or not the Hardware model uses SoftDeletes.
        $exists = DB::table('hardwares')->where('id', $audit->hardware_id)->exists();
        if ($exists) {
            return response()->json(['message' => 'This hardware record still exists — use rollback instead.'], 422);
        }

        // Verify the audit is not orphaned (linked hardware has never existed or no changes)
        if (! $audit->changes) {
            return response()->json(['message' => 'No change data in audit to restore from.'], 422);
        }

        $user = $request->user();
        $this->assertAccessibleFromAudit($request, $audit);

        // Build restore data from the 'new' values of the 'created' audit
        $restoreData = [];
        foreach ($audit->changes as $change) {
            if (! isset($change['field'], $change['new'])) {
                continue;
            }
            $restoreData[$change['field']] = $this->parseValueForRestore(
                $change['new'],
                $change['field']
            );
        }

        if (empty($restoreData)) {
            return response()->json(['message' => 'No field data found to restore.'], 422);
        }

        $restoreData['id'] = $audit->hardware_id;

        // #888: `id` is not in Hardware::$fillable, so Hardware::create() dropped
        // it silently and the row landed under a fresh auto-increment id. That
        // made the `exists` guard above permanently blind — it checks the
        // original id, which never came back — so restoring the same audit twice
        // created a duplicate row and orphaned the audit trail. forceFill()
        // bypasses $fillable; this mirrors HardwareIndexHelpers::restoreRecord()
        // so the two paths cannot drift apart again.
        //
        // #1698: the write block is one transaction. Because the id is explicit,
        // the primary key itself serialises two concurrent restores — the loser
        // fails with a unique violation that we map to the existing 422 below.
        $rollbackChanges = array_map(
            fn ($change) => ['field' => $change['field'], 'old' => 'حذف شده', 'new' => $change['new'] ?? '—'],
            $audit->changes
        );

        try {
            $hardware = DB::transaction(function () use ($restoreData, $rollbackChanges, $user): Hardware {
                $hardware = new Hardware;
                $hardware->forceFill($restoreData)->save();

                // Advance the Postgres sequence past the restored id so the next
                // auto-increment does not collide (duplicate key on hardwares_pkey).
                // pg_get_serial_sequence resolves the sequence name regardless of
                // SERIAL vs IDENTITY column definition.
                // #1698: setval is non-transactional and deliberately stays inside
                // the transaction — a rollback only leaves a harmless id gap,
                // whereas running it after commit risks the sequence lagging the
                // restored row if the process dies in between.
                if (DB::connection()->getDriverName() === 'pgsql') {
                    $seq = DB::selectOne("SELECT pg_get_serial_sequence('hardwares','id') as seq");
                    if ($seq && $seq->seq) {
                        DB::statement('SELECT setval(?, (SELECT COALESCE(MAX(id), 0) FROM hardwares))', [$seq->seq]);
                    }
                }

                // Hardware::save() already fired HardwareAuditObserver::created()
                // inside this transaction, so only the explicit 'rollback' audit
                // is written here (calling created() again would duplicate it).
                app(HardwareAuditObserver::class)->recordRollbackAudit(
                    $hardware,
                    $rollbackChanges,
                    $user?->id
                );

                return $hardware;
            });
        } catch (UniqueConstraintViolationException) {
            // hardwares has exactly one unique constraint — the primary key — so
            // losing the race can only mean the row was already restored.
            // Caught OUTSIDE the transaction: catching inside would leave the
            // Postgres transaction aborted (25P02).
            return response()->json(['message' => 'This hardware record still exists — use rollback instead.'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'سخت‌افزار با موفقیت بازگردانده شد.',
            'data' => ['hardware_id' => $hardware->id],
        ]);
    }

    /**
     * Export audit trail as CSV/Excel for compliance.
     */
    public function export(UnitScopedRequest $request, Hardware $hardware)
    {
        $this->assertAccessible($request, $hardware);

        $query = HardwareAudit::where('hardware_id', $hardware->id)
            ->with('user.person:id,n_code,f_name,l_name')
            ->latest('created_at');

        // Apply same filters as index
        if ($request->filled('field')) {
            $query->whereJsonContains('changes', ['field' => $request->field]);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        // #1766: format used to reach Excel::download unvalidated, which let a
        // caller select any writer the library accepts (html/xls/ods download
        // unexpected content; pdf/slk/xml/unknown typed 500). Only the two
        // formats the UI offers are allowed, case-insensitively (the writer
        // detector lowercases the extension too), and an array input is a
        // validation failure rather than a TypeError.
        $format = $request->query('format', 'xlsx');

        if (! is_string($format) || ! in_array(strtolower($format), ['xlsx', 'csv'], true)) {
            return response()->json([
                'message' => 'The format field must be one of: xlsx, csv.',
                'errors' => ['format' => ['The format field must be one of: xlsx, csv.']],
            ], 422);
        }

        $format = strtolower($format);
        $filename = "hardware-{$hardware->pc_name}-audits-".now()->format('Ymd-His');

        return Excel::download(
            new HardwareAuditsExport($query, csv: $format === 'csv'),
            "{$filename}.{$format}"
        );
    }

    /**
     * Transform audit to array.
     */
    protected function transformAudit(HardwareAudit $audit, bool $withFullDiff = false): array
    {
        $data = [
            'id' => $audit->id,
            'action' => $audit->action,
            'action_label' => $this->getActionLabel($audit->action),
            'source' => $audit->source,
            'source_label' => $this->getSourceLabel($audit->source),
            'changes' => $audit->changes,
            'ip_address' => $audit->ip_address,
            'user_agent' => $audit->user_agent,
            'created_at' => $audit->created_at?->toIso8601String(),
            'created_at_jalali' => $audit->created_at
                ? Jalalian::fromCarbon($audit->created_at)->format('Y/m/d H:i:s')
                : null,
            'user' => $audit->user ? [
                'id' => $audit->user->id,
                'n_code' => $audit->user->n_code,
                'name' => $audit->user->name,
            ] : null,
        ];

        if ($withFullDiff && $audit->changes) {
            $data['diff_summary'] = $this->buildDiffSummary($audit->changes);
        }

        return $data;
    }

    /**
     * Get Persian label for action.
     */
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

    /**
     * Get Persian label for source.
     */
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

    /**
     * Build human-readable diff summary.
     */
    protected function buildDiffSummary(array $changes): array
    {
        $summary = [];
        foreach ($changes as $change) {
            $summary[] = 'فیلد <strong>'.e($change['field'])."</strong>: از <span class='text-error'>".e($change['old'])."</span> به <span class='text-success'>".e($change['new']).'</span> تغییر یافت.';
        }

        return $summary;
    }

    /**
     * Parse value for restore (reverse of formatValueForDisplay).
     */
    protected function parseValueForRestore(string $displayValue, string $field): mixed
    {
        // #1748: the audit trail stores ExcelCell::escape()'d strings; undo
        // that before type parsing so the column gets the value that was
        // stored, not the export-safe form. Must stay ahead of the JSON branch
        // — a stored "=[...]" value is a plain string, not an array.
        $displayValue = ExcelCell::unescape($displayValue);

        if ($displayValue === '—') {
            return null;
        }
        if ($displayValue === 'بله') {
            return true;
        }
        if ($displayValue === 'خیر') {
            return false;
        }

        // Try to parse as JSON for arrays
        if (str_starts_with($displayValue, '[') || str_starts_with($displayValue, '{')) {
            $decoded = json_decode($displayValue, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Handle numeric fields
        $numericFields = ['ram', 'vlan', 'port'];
        if (in_array($field, $numericFields, true) && is_numeric($displayValue)) {
            return (int) $displayValue;
        }

        return $displayValue;
    }

    /**
     * Check if the hardware is within user's organizational scope.
     */
    private function assertAccessible(UnitScopedRequest $request, Hardware $hardware): void
    {
        $user = $request->user();
        $accessibleIds = $request->accessibleIds();

        $unitId = $hardware->relationLoaded('person')
            ? $hardware->person?->u_id
            : $hardware->person()->value('u_id');

        if (! $unitId || ! in_array($unitId, $accessibleIds)) {
            abort(403, 'Hardware record not accessible.');
        }
    }

    /**
     * Check organizational access from an audit record (hardware may be gone).
     *
     * Scope resolution itself lives in App\Services\HardwareAuditScope, the
     * single implementation shared with the Livewire hardware trail.
     * For a live hardware row we read its n_code directly; for a hard-deleted
     * row (the common case for restoreRecord) we fall back to the n_code stored
     * in the audit snapshot so the org-scope check is never skipped.
     */
    private function assertAccessibleFromAudit(UnitScopedRequest $request, HardwareAudit $audit): void
    {
        $unitId = app(HardwareAuditScope::class)->unitIdForAudit($audit);

        // Deny by default when scope cannot be proven — prevents IDOR when
        // audit data is missing n_code (legacy/corrupted/manual inserts).
        if ($unitId === null || ! in_array($unitId, $request->accessibleIds(), true)) {
            abort(403, 'Hardware record not accessible.');
        }
    }
}
