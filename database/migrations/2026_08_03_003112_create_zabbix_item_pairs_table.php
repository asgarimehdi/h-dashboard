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
        Schema::create('zabbix_item_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zabbix_host_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Link name (e.g., "فیبر اصلی", "سوئیچ A")
            $table->foreignId('out_item_id')->constrained('zabbix_items')->cascadeOnDelete();
            $table->foreignId('in_item_id')->constrained('zabbix_items')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete(); // Organizational scope
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index('zabbix_host_id');
            $table->index('unit_id');
            $table->index('is_active');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zabbix_item_pairs');
    }
};
