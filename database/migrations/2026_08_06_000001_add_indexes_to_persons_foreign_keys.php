<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->index('t_id', 'persons_t_id_index');
            $table->index('e_id', 'persons_e_id_index');
            $table->index('s_id', 'persons_s_id_index');
            $table->index('r_id', 'persons_r_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropIndex('persons_t_id_index');
            $table->dropIndex('persons_e_id_index');
            $table->dropIndex('persons_s_id_index');
            $table->dropIndex('persons_r_id_index');
        });
    }
};
