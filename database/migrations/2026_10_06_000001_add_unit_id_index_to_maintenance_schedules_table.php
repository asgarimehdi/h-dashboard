<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * issue #835 (Plan 46): the maintenance list now filters on `unit_id` on every
 * render, and the column carries only its FK constraint — no standalone index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->index('unit_id', 'maint_sched_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropIndex('maint_sched_unit_idx');
        });
    }
};
