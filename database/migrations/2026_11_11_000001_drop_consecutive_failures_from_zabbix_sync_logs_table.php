<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the dead Zabbix failure-streak column (#1420, decision D1).
     *
     * A fresh migration, deliberately not an edit of
     * 2026_09_30_000002_create_zabbix_sync_logs_table: environments that
     * already ran it would keep the column on a file edit and drift. The
     * log rows stay; a streak remains re-derivable from `success` history.
     */
    public function up(): void
    {
        Schema::table('zabbix_sync_logs', function (Blueprint $table): void {
            $table->dropColumn('consecutive_failures');
        });
    }

    public function down(): void
    {
        Schema::table('zabbix_sync_logs', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_failures')->default(0);
        });
    }
};
