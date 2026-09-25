<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zabbix_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // 'network' | 'wireless' — which display page renders the row
            $table->string('type', 20);
            // network traffic pair
            $table->string('out_item_id')->nullable();
            $table->string('in_item_id')->nullable();
            // wireless gauge trio
            $table->string('signal_item_id')->nullable();
            $table->string('frequency_item_id')->nullable();
            $table->string('response_item_id')->nullable();
            // chart default window (network)
            $table->unsignedInteger('initial_duration')->default(3600);
            // gauge range (wireless, -85/-45 dBm by default)
            $table->float('min')->nullable();
            $table->float('max')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zabbix_devices');
    }
};
