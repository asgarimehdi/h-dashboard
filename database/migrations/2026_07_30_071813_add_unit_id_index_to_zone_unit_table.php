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
        Schema::table('zone_units', function (Blueprint $table) {
            $table->index('unit_id', 'zone_units_unit_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zone_units', function (Blueprint $table) {
            $table->dropIndex('zone_units_unit_id_index');
        });
    }
};