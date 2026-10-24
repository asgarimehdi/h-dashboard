<?php

use App\Models\HardwareAudit;
use App\Support\HardwareAuditChange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #927: repair the `hardware_audits.changes` rows already stored in the wrong
 * shape.
 *
 * `HardwareController::bulkDelete()` used to hand `$hw->getAttributes()` — a
 * column => value map — to `batchInsertAudits()`, so every row it wrote is a
 * JSON object where the column documents a diff list, `[{field, old, new}]`.
 * Those rows are unrepairable from the UI (there is no delete route for an audit
 * row) and they outlive the device by design, since `hardware_id` deliberately
 * carries no FK.
 *
 * The rows are normalised **in place**, not nulled: the migration documents
 * that the audit trail must survive hardware deletion, and blanking `changes`
 * would discard data the schema was built to keep. The who / when / action
 * columns are untouched.
 *
 * Runs in PHP rather than a bulk SQL statement so the repaired rows are written
 * by exactly the same {@see HardwareAuditChange::toDiffList()} the writer uses —
 * the two can therefore never disagree about the shape. It is shape-driven, not
 * action-driven, so any action that hit the bug is repaired.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        $lastId = 0;

        do {
            $audits = HardwareAudit::query()
                ->select(['id', 'changes'])
                ->where('id', '>', $lastId)
                ->whereNotNull('changes')
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();

            foreach ($audits as $audit) {
                $lastId = $audit->id;

                if (HardwareAuditChange::isDiffList($audit->changes)) {
                    continue;
                }

                $normalized = HardwareAuditChange::toDiffList($audit->changes);

                if ($normalized === null) {
                    continue;
                }

                DB::table('hardware_audits')
                    ->where('id', $audit->id)
                    ->update(['changes' => json_encode($normalized, JSON_UNESCAPED_UNICODE)]);
            }
        } while ($audits->count() === self::CHUNK);
    }

    /**
     * Irreversible on purpose: turning a diff list back into the original
     * attribute map would have to invent a `new` value for every entry, and the
     * pre-migration payload is not recoverable. Re-running `up()` is a no-op.
     */
    public function down(): void
    {
        // Intentionally empty — see the note above.
    }
};
