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
        Schema::create('zabbix_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zabbix_host_id')->constrained()->cascadeOnDelete();
            $table->string('item_id')->unique(); // Zabbix item ID (e.g., 73638)
            $table->string('item_key'); // Zabbix item key (e.g., net.if.out[eth0])
            $table->string('name'); // Display name
            $table->enum('type', ['traffic_in', 'traffic_out', 'cpu', 'memory', 'disk', 'custom'])->default('custom');
            $table->string('unit')->nullable(); // Unit of measurement (Mbps, %, GB, etc.)
            $table->enum('value_type', ['numeric_float', 'uint', 'text', 'log'])->default('numeric_float');
            $table->string('delay')->default('60s'); // Polling interval (e.g., 60s)
            $table->boolean('is_monitored')->default(true); // Whether to show in dashboards
            $table->integer('display_order')->default(0); // Sort order in UI
            $table->json('last_value')->nullable(); // Cached latest value from Zabbix
            $table->timestamp('last_check_at')->nullable();
            $table->timestamps();

            $table->index('zabbix_host_id');
            $table->index('type');
            $table->index('is_monitored');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zabbix_items');
    }
};
