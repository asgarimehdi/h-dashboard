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
        // Add index on parent_id for units table (hierarchical unit structure)
        Schema::table('units', function (Blueprint $table) {
            $table->index('parent_id', 'units_parent_id_index');
        });

        // Add index on parent_id for regions table (hierarchical region structure)
        Schema::table('regions', function (Blueprint $table) {
            $table->index('parent_id', 'regions_parent_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_parent_id_index');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->dropIndex('regions_parent_id_index');
        });
    }
};