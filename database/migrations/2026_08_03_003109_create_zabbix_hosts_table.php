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
        Schema::create('zabbix_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hardware_id')->nullable()->constrained('hardwares')->nullOnDelete();
            $table->string('host_id')->unique(); // Zabbix host ID (e.g., 10084)
            $table->string('host_name'); // Zabbix host name
            $table->string('visible_name'); // Display name in UI
            $table->string('ip')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'disabled', 'maintenance'])->default('active');
            $table->json('template_ids')->nullable(); // Associated Zabbix template IDs
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index('unit_id');
            $table->index('hardware_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zabbix_hosts');
    }
};
