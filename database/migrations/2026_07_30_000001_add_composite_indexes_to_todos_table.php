<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite indexes for the most frequent todo queries:
     *
     * 1. (is_completed, created_at) — report counts, sorting by completed status
     * 2. (is_completed, end_at) — overdue queries: WHERE is_completed=false AND end_at < now
     * 3. (start_at) — daily grouping in reports: GROUP BY date(start_at)
     * 4. (unit_id, is_completed) — filtered listing in TodoController::index
     */
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            // Index 1: covers is_completed filtering + created_at ordering (reports, listing)
            $table->index(['is_completed', 'created_at'], 'todos_completed_created_idx');

            // Index 2: covers overdue deadline queries (is_completed=false + end_at < now)
            $table->index(['is_completed', 'end_at'], 'todos_completed_end_at_idx');

            // Index 3: covers GROUP BY date(start_at) in reports
            $table->index(['start_at'], 'todos_start_at_idx');

            // Index 4: covers unit_id + is_completed filtering (API index)
            $table->index(['unit_id', 'is_completed'], 'todos_unit_completed_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex('todos_completed_created_idx');
            $table->dropIndex('todos_completed_end_at_idx');
            $table->dropIndex('todos_start_at_idx');
            $table->dropIndex('todos_unit_completed_idx');
        });
    }
};
