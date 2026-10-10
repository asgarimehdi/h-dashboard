<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #929 — `archived_at` timestamp on `tickets`.
 *
 * `tools.tools::archiveTickets()` used to write `status = 'archived'`, a value
 * outside every status vocabulary: the label fell back to «نامشخص», the API
 * `in:` rule answered 422, open-ticket predicates (`!= 'completed'`) kept
 * counting the row as open, dead action buttons kept rendering, and no path
 * led back. Archiving is now a timestamp on a completed ticket instead:
 * `status` stays `'completed'`, so every existing reader keeps its meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('completed_at');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_archived_at_index');
            $table->dropColumn('archived_at');
        });
    }
};
