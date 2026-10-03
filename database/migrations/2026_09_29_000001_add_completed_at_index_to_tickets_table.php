<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #736 — index `tickets.completed_at`.
 *
 * The column has existed since `2025_12_26_000001_create_tickets_table`, but the
 * only indexes on `tickets` cover `status`/`created_at`/`task_id`/
 * `current_assignee_id`/`unit_id`. Every average-resolution-time aggregate
 * filters on `completed_at IS NOT NULL` and sorts by it, so without this index
 * those queries are a full table scan that gets worse as tickets accumulate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_completed_at_index');
        });
    }
};
