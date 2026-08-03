<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add HR fields to persons table (Issue #223).
     */
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('l_name');
            $table->date('hire_date')->nullable()->after('birth_date');
            $table->string('status', 20)->default('active')->after('u_id'); // active | inactive | retired
        });

        // Indexes for HR queries
        Schema::table('persons', function (Blueprint $table) {
            $table->index('status');
            $table->index('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['birth_date']);
            $table->dropColumn(['birth_date', 'hire_date', 'status']);
        });
    }
};
