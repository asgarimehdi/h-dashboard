<?php

namespace App\Http\Controllers\Api;

use App\Exports\HardwareAuditsExport;
use App\Http\Controllers\Controller;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Observers\HardwareAuditObserver;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Morilog\Jalali\Jalalian;

class HardwareAuditController extends Controller
{
    /**
     * Display a paginated list of audits for a hardware item.
     */
    public function index(Request $request, Hardware $hardware): JsonResponse
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
    public function show(Request $request, Hardware $hardware, HardwareAudit $audit): JsonResponse
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
    public function rollback(Request $request, Hardware $hardware, HardwareAudit $audit): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        if ($audit->hardware_id !== $hardware->id) {
            return response()->json(['message' => 'Audit not found for this hardware.'], 404);
        }

        $request->validate([
            'field' => 'required|string',
        ]);

        $changes = $audit->changes;
        if (! $changes || ! is_array($changes)) {
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

        $oldValue = $fieldChange['old'] ?? '—';
        $newValue = $fieldChange['new'] ?? '—';

        // Parse the old value back to its original type
        $restoredValue = $this->parseValueForRestore($oldValue, $request->field);

        // Update the hardware record
        $hardware->update([$request->field => $restoredValue]);

        // Log the rollback as a new audit entry
        $rollbackChanges = [[
            'field' => $request->field,
            'old' => $newValue,
            'new' => $oldValue,
        ]];

        app(HardwareAuditObserver::class)
            ->recordRollbackAudit($hardware, $rollbackChanges, $request->user()?->id);

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
    public function restoreRecord(Request $request, HardwareAudit $audit): JsonResponse
    {
        if ($audit->action !== 'created') {
            return response()->json(['message' => 'Only "created" audits can be used to restore a record.'], 422);
        }

        // Verify the hardware was actually deleted
        $exists = Hardware::withTrashed()->where('id', $audit->hardware_id)->exists();
        if ($exists) {
            return response()->json(['message' => 'This hardware record still exists — use rollback instead.'], 422);
        }

        // Verify the audit is not orphaned (linked hardware has never existed or no changes)
        if (! $audit->changes || ! is_array($audit->changes)) {
            return response()->json(['message' => 'No change data in audit to restore from.'], 422);
        }

        $user = $request->user();
        $this->assertAccessibleFromAudit($request, $audit);

        // Build restore data from the 'new' values of the 'created' audit
        $restoreData = [];
        foreach ($audit->changes as $change) {
            if (!isset($change['field'], $change['new'])) {
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

        $hardware = Hardware::create($restoreData);

        // Log a new 'created' audit entry for the restored record
        app(HardwareAuditObserver::class)->created($hardware);

        // Also log an explicit 'rollback' audit for traceability
        $rollbackChanges = array_map(
            fn($change) => ['field' => $change['field'], 'old' => 'حذف شده', 'new' => $change['new']],
            $audit->changes
        );
        app(HardwareAuditObserver::class)->recordRollbackAudit(
            $hardware,
            $rollbackChanges,
            $user?->id
        );

        return response()->json([
            'success' => true,
            'message' => 'سخت‌افزار با موفقیت بازگردانده شد.',
            'data' => ['hardware_id' => $hardware->id],
        ]);
    }

    /**
     * Export audit trail as CSV/Excel for compliance.
     */
    public function export(Request $request, Hardware $hardware)
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

        $format = $request->get('format', 'xlsx');
        $filename = "hardware-{$hardware->pc_name}-audits-".now()->format('Ymd-His');

        return Excel::download(
            new HardwareAuditsExport($query),
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
            $summary[] = "فیلد <strong>{$change['field']}</strong>: از <span class='text-error'>{$change['old']}</span> به <span class='text-success'>{$change['new']}</span> تغییر یافت.";
        }

        return $summary;
    }

    /**
     * Parse value for restore (reverse of formatValueForDisplay).
     */
    protected function parseValueForRestore(string $displayValue, string $field): mixed
    {
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
    private function assertAccessible(Request $request, Hardware $hardware): void
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $unitId = $hardware->relationLoaded('person')
            ? $hardware->person?->u_id
            : $hardware->person()->value('u_id');

        if (! $unitId || ! in_array($unitId, $accessibleIds)) {
            abort(403, 'Hardware record not accessible.');
        }
    }

    /**
     * Check organizational access from an audit record (hardware may be gone).
     */
    private function assertAccessibleFromAudit(Request $request, HardwareAudit $audit): void
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $hw = DB::table('hardwares')->where('id', $audit->hardware_id)->first();
        if ($hw && isset($hw->n_code)) {
            $unitId = DB::table('persons')->where('n_code', $hw->n_code)->value('u_id');
            if ($unitId && ! in_array($unitId, $accessibleIds)) {
                abort(403, 'Hardware record not accessible.');
            }
        }
    }
}
