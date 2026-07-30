<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite indexes for the most frequent persons queries:
     *
     * 1. (u_id, n_code) — covers unit filtering + n_code search (PersonController::index line 26)
     * 2. (u_id, f_name, l_name) — covers unit filtering + name search (PersonController::index line 27-28)
     * 3. (u_id, s_id) — covers unit + semat filtering (PersonController::index line 36-38)
     * 4. (u_id, s_id, n_code) — covers import lookup pattern (HardwareImport::loadExistingRecords)
     */
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            // Index 1: unit + n_code search (most common filter + search combo)
            $table->index(['u_id', 'n_code'], 'persons_u_id_n_code_idx');

            // Index 2: unit + first/last name search (name search)
            $table->index(['u_id', 'f_name', 'l_name'], 'persons_u_id_f_name_l_name_idx');

            // Index 3: unit + semat filtering (PersonController filter)
            $table->index(['u_id', 's_id'], 'persons_u_id_s_id_idx');

            // Index 4: import lookup pattern (u_id + s_id + n_code for bulk lookups)
            $table->index(['u_id', 's_id', 'n_code'], 'persons_u_id_s_id_n_code_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropIndex('persons_u_id_n_code_idx');
            $table->dropIndex('persons_u_id_f_name_l_name_idx');
            $table->dropIndex('persons_u_id_s_id_idx');
            $table->dropIndex('persons_u_id_s_id_n_code_idx');
        });
    }
};
