<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent per-run record of the Zabbix sync job (#740).
     *
     * The job runs every 5 minutes; before this, its only witness was a log
     * line. One row per run answers "when did sync last succeed" (dashboard
     * widget) and "how many runs failed in a row" (the alert threshold),
     * without parsing logs.
     */
    public function up(): void
    {
        Schema::create('zabbix_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->boolean('success');
            // Streak of consecutive failures INCLUDING this row (0 on a
            // success row) — precomputed so the threshold check and the
            // widget are single-row reads.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('ran_at');
            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zabbix_sync_logs');
    }
};
