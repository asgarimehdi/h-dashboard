<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the unused patient `city` column (#1261, decision «ب» — full removal).
     *
     * A fresh migration, deliberately not an edit of
     * 2026_10_25_000001_create_patients_table: environments that already ran
     * that migration would keep the column on a file edit and drift. The
     * field had no consumers (no API, export, report or search read it).
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn('city');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('city')->nullable();
        });
    }
};
