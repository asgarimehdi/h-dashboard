<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ticket and todo search run LIKE '%term%' (leading wildcard) against
     * tickets.subject, tickets.content and todos.title — none of which a B-tree
     * index can serve, so every keystroke forced a sequential scan that grows
     * with the table. These inputs are all `wire:model.live`, so the scan was
     * paid on every character typed. `tickets` is the fastest-growing table in
     * the application, which is what makes the cost curve matter (issue #871).
     *
     * pg_trgm GIN indexes already exist for persons, hardwares and units — this
     * migration closes the same gap for tickets and todos.
     *
     * `tickets.ticket_code` is deliberately NOT indexed with GIN: it already
     * carries a unique B-tree, which serves the exact and prefix lookups a code
     * is actually entered with, and a trigram index cannot serve a
     * leading-wildcard match more cheaply than that. Adding one would only add
     * write amplification to the fastest-growing table. That was a closed
     * decision on the issue. If fragment-matching on codes is ever needed, it
     * is a separate decision.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Searched with leading-wildcard LIKE in tickets/⚡inbox and
        // search/index (⚡monitoring searches subject only).
        DB::statement(
            'CREATE INDEX IF NOT EXISTS tickets_subject_trgm_idx ON tickets USING GIN (subject gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS tickets_content_trgm_idx ON tickets USING GIN (content gin_trgm_ops)'
        );

        // Searched with leading-wildcard LIKE in search/index.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS todos_title_trgm_idx ON todos USING GIN (title gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS tickets_subject_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS tickets_content_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS todos_title_trgm_idx');
    }
};
