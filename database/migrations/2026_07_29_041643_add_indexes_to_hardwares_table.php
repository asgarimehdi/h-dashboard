<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hardwares', function (Blueprint $table) {
            $table->index(['type', 'os', 'shutdown'], 'hardwares_type_os_shutdown_index');
            $table->index('cpu');
            $table->index('ram');
            $table->index('hdd');
            $table->index('shutdown');
            $table->index('mark');
            $table->index('net_type');
            $table->index('ip_local');
            $table->index('mac');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hardwares', function (Blueprint $table) {
            $table->dropIndex('hardwares_type_os_shutdown_index');
            $table->dropIndex('hardwares_cpu_index');
            $table->dropIndex('hardwares_ram_index');
            $table->dropIndex('hardwares_hdd_index');
            $table->dropIndex('hardwares_shutdown_index');
            $table->dropIndex('hardwares_mark_index');
            $table->dropIndex('hardwares_net_type_index');
            $table->dropIndex('hardwares_ip_local_index');
            $table->dropIndex('hardwares_mac_index');
        });
    }
};