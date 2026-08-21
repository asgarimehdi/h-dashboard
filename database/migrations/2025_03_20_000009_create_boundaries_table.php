<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostGIS must exist before creating geometry columns. On CI the
        // database is created from the plain postgis image template (no
        // template_postgis), so enable the extension explicitly here —
        // NOT in a later migration (2026_08_03) which runs AFTER this table.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');
        }

        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->geometry('boundary', 'MULTIPOLYGON')->srid(4326);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boundaries');
    }
};
