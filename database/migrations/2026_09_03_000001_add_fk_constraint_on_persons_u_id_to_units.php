<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(
            'ALTER TABLE persons ADD CONSTRAINT persons_u_id_foreign_units FOREIGN KEY (u_id) REFERENCES units(id) ON DELETE SET NULL ON UPDATE CASCADE;'
        );
    }

    public function down(): void
    {
        DB::unprepared(
            'ALTER TABLE persons DROP CONSTRAINT persons_u_id_foreign_units;'
        );
    }
};
