<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');
        }
        Schema::table('units', function (Blueprint $table) {
            if (DB::getDriverName() === 'pgsql') {
                $table->geometry('geom', 'Point', 4326)->nullable()->after('lng');
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE units SET geom = ST_SetSRID(ST_MakePoint(lng::double precision, lat::double precision), 4326) WHERE lat IS NOT NULL AND lng IS NOT NULL;');
            DB::statement('CREATE INDEX idx_units_geom ON units USING GIST(geom);');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_units_geom;');
        }
        Schema::table('units', function (Blueprint $table) {
            if (DB::getDriverName() === 'pgsql') {
                $table->dropColumn('geom');
            }
        });
    }
};
