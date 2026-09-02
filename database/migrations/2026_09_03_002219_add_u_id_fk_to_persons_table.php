<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean orphaned records before adding FK
        DB::statement('
            UPDATE persons
            SET u_id = (SELECT id FROM units ORDER BY id LIMIT 1)
            WHERE u_id NOT IN (SELECT id FROM units)
              AND EXISTS (SELECT 1 FROM units)
        ');

        Schema::table('persons', function (Blueprint $table) {
            $table->foreign('u_id', 'persons_u_id_fk')
                ->references('id')->on('units')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropForeign('persons_u_id_fk');
        });
    }
};
