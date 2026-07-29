<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite indexes for the most frequent ticket queries:
     *
     * 1. (status, created_at) — inbox, monitoring, API: WHERE status IN(...) + ORDER BY created_at DESC
     * 2. (unit_id, status, created_at) — monitoring, API: WHERE unit_id=? AND status=? + ORDER BY created_at DESC
     * 3. (current_assignee_id, status) — "assigned to me": WHERE current_assignee_id=? AND status IN(...)
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Index 1: covers status filtering + created_at ordering (most frequent)
            $table->index(['status', 'created_at'], 'tickets_status_created_at_idx');

            // Index 2: covers unit + status filtering + created_at ordering (monitoring, API)
            $table->index(['unit_id', 'status', 'created_at'], 'tickets_unit_status_created_idx');

            // Index 3: covers assigned-to-me filter (inbox, API)
            $table->index(['current_assignee_id', 'status'], 'tickets_assignee_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_status_created_at_idx');
            $table->dropIndex('tickets_unit_status_created_idx');
            $table->dropIndex('tickets_assignee_status_idx');
        });
    }
};
