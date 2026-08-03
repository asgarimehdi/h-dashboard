<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_links', function (Blueprint $table) {
            $table->id();
            $table->string('source_switch');          // From hardware.switch
            $table->string('target_switch');          // From hardware.switch
            $table->string('link_type')->default('unknown'); // fiber, wireless, mpls, vpn, unknown
            $table->json('vlans')->nullable();        // ["10","20","100"]
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('bandwidth_mbps')->nullable();
            $table->boolean('is_redundant')->default(false);
            $table->unsignedBigInteger('source_unit_id')->nullable();
            $table->unsignedBigInteger('target_unit_id')->nullable();
            $table->timestamps();

            $table->index('source_switch');
            $table->index('target_switch');
            $table->index('link_type');
            $table->foreign('source_unit_id')->references('id')->on('units')->nullOnDelete();
            $table->foreign('target_unit_id')->references('id')->on('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_links');
    }
};
