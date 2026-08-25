<?php

namespace Database\Seeders;

use App\Models\HardwareAudit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed the hardware audit / history trail (hardware_audits table).
 *
 * This is the STANDARDIZED seeder for the "hardware history" feature. The
 * history is keyed by the application-wide standard key `n_code`
 * (hardware <-> person <-> user), exactly like every other seeder in the
 * project (HardwareSeeder, UserUnitSeeder, TodoSeeder, ...).
 *
 * The data lives in data/hardware_histories_data.php so it can be edited
 * without touching the seeder logic.
 *
 * Idempotency: the initial "created" audit for each device is produced
 * automatically by HardwareAuditObserver when HardwareSeeder runs. This
 * seeder adds the SUBSEQUENT changes. To stay idempotent it skips any
 * n_code whose hardware does not exist, and uses a fixed deterministic
 * base date so timestamps are stable across re-runs. Rows are guarded by
 * a unique (hardware_id, action, source, user_id, created_at) signature so
 * re-running db:seed never creates duplicates.
 *
 * Note: HardwareAudit has NO observer, so inserting rows here has no
 * side effects on the hardware table (no cache flush, no recursion).
 */
class HardwareHistorySeeder extends Seeder
{
    /**
     * Fixed base date so the seeder is deterministic / idempotent.
     */
    protected Carbon $baseDate;

    public function run(): void
    {
        $records = require __DIR__.'/data/hardware_histories_data.php';

        if (empty($records)) {
            return;
        }

        // Stable base so re-runs produce identical timestamps.
        $this->baseDate = Carbon::parse('2026-08-25 12:00:00');

        // Map n_code -> user id for the actors named in the data file.
        $userIdsByNcode = User::query()
            ->whereIn('n_code', $this->actorNcodes($records))
            ->pluck('id', 'n_code')
            ->all();

        $this->command?->info('Seeding hardware audit history for '.count($records).' devices...');

        $inserted = 0;
        $skipped = 0;

        foreach ($records as $nCode => $entries) {
            $hardware = DB::table('hardwares')->where('n_code', $nCode)->first();

            if (! $hardware) {
                // Device not seeded (e.g. filtered out) — skip silently.
                $skipped++;
                continue;
            }

            foreach ($entries as $entry) {
                $userId = null;
                if (! empty($entry['actor_ncode'])) {
                    $userId = $userIdsByNcode[$entry['actor_ncode']] ?? null;
                }

                $createdAt = $this->baseDate->copy()->subDays((int) ($entry['days_ago'] ?? 0));

                $signature = [
                    'hardware_id' => $hardware->id,
                    'action' => $entry['action'],
                    'source' => $entry['source'] ?? 'web',
                    'user_id' => $userId,
                    'created_at' => $createdAt,
                ];

                // Idempotency guard: skip if an identical audit already exists.
                $exists = HardwareAudit::query()
                    ->where($signature)
                    ->exists();

                if ($exists) {
                    continue;
                }

                HardwareAudit::create(array_merge($signature, [
                    'changes' => $entry['changes'] ?? null,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Mozilla/5.0 (compatible; HardwareHistorySeeder)',
                    'updated_at' => $createdAt,
                ]));

                $inserted++;
            }
        }

        $this->command?->info("Hardware history seeded: {$inserted} new audit entries".($skipped ? ", {$skipped} devices skipped (not found)." : '.'));
    }

    /**
     * Collect every actor n_code referenced in the data so we can resolve
     * them all in a single query instead of per-entry lookups.
     */
    protected function actorNcodes(array $records): array
    {
        $codes = [];

        foreach ($records as $entries) {
            foreach ($entries as $entry) {
                if (! empty($entry['actor_ncode'])) {
                    $codes[] = $entry['actor_ncode'];
                }
            }
        }

        return array_values(array_unique($codes));
    }
}
