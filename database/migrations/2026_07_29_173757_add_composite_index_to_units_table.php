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
        Schema::table('units', function (Blueprint $table) {
            // Composite index for Recursive CTE optimization
            // CTE uses: WHERE u.is_active = true AND u.parent_id = ut.id
            // Column order: is_active (equality filter) first, then parent_id (join)
            $table->index(['is_active', 'parent_id'], 'units_is_active_parent_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_is_active_parent_id_index');
        });
    }
};
