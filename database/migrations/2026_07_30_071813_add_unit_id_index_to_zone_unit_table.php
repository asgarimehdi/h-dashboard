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
        Schema::table('zone_unit', function (Blueprint $table) {
            $table->index('unit_id', 'zone_unit_unit_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zone_unit', function (Blueprint $table) {
            $table->dropIndex('zone_unit_unit_id_index');
        });
    }
};