<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->unsignedBigInteger('u_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $nullRows = DB::table('persons')->whereNull('u_id')->count();

        if ($nullRows > 0) {
            throw new RuntimeException(
                "Rollback aborted: persons.u_id has {$nullRows} NULL row(s) — "
                .'left there by a unit deletion (FK ON DELETE SET NULL). '
                .'Assign a unit (`UPDATE persons SET u_id = <unit_id> WHERE u_id IS NULL`) first.'
            );
        }

        Schema::table('persons', function (Blueprint $table) {
            $table->unsignedBigInteger('u_id')->nullable(false)->change();
        });
    }
};
