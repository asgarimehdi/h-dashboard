<?php

use App\Traits\PersianNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ticket search (#1056) folds Persian character variants on BOTH sides: the
     * typed term via `PersianNormalizer::foldedTerm()` and each stored column
     * via `PersianNormalizer::foldSeparatorsSql()`. The predicate therefore sits
     * on an *expression*, so the bare-column GIN indexes added on 2026-10-08
     * (`tickets_subject_trgm_idx`, `tickets_content_trgm_idx`) can never serve
     * it — every debounced keystroke on the inbox and monitoring pages still
     * scanned `tickets` and re-ran the fold regex per row (issue #1165).
     *
     * These are expression indexes mirroring that fold exactly. The SQL is
     * generated from `foldSeparatorsSql()` rather than copied, so it cannot
     * drift from `charMap()`/`digitMap()`; a test pins the catalog definition
     * against the helper for the same reason.
     *
     * `ticket_code` is included deliberately: both pages OR `subject`,
     * `ticket_code` and (inbox only) `content` in one predicate, and PostgreSQL
     * only builds a BitmapOr when EVERY branch is indexable. With only two
     * indexes the whole OR stays a sequential scan (owner decision, 2026-10-20).
     * This does NOT contradict the earlier "no trigram on ticket_code" decision:
     * that was about the BARE column, which keeps its unique B-tree and still
     * gets no GIN index.
     *
     * Plain `CREATE INDEX`, not `CONCURRENTLY`: migrations run inside a schema
     * transaction (PostgreSQL rejects CONCURRENTLY there), and the existing
     * trigram migration made the same choice at this table's size.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Read the trait through a consumer class: calling a static method on a
        // trait directly is deprecated since PHP 8.1.
        $normalizer = new class
        {
            use PersianNormalizer;
        };

        $indexes = [
            'tickets_subject_folded_trgm_idx' => $normalizer->foldSeparatorsSql('subject'),
            'tickets_content_folded_trgm_idx' => $normalizer->foldSeparatorsSql('content'),
            'tickets_ticket_code_folded_trgm_idx' => $normalizer->foldSeparatorsSql('ticket_code'),
        ];

        foreach ($indexes as $name => $expression) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON tickets USING GIN (({$expression}) gin_trgm_ops)");
        }
    }

    /**
     * Drops only the folded-expression indexes. The bare-column trigram indexes
     * stay: non-folded searches (global search, `where('subject', 'like', …)`)
     * still rely on them.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS tickets_subject_folded_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS tickets_content_folded_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS tickets_ticket_code_folded_trgm_idx');
    }
};
