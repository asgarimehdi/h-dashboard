<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable PostGIS extension if not already enabled
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');

        Schema::table('units', function (Blueprint $table) {
            // Add geometry column for spatial queries
            $table->geometry('geom', 'Point', 4326)->nullable()->after('lng');
        });

        // Populate geom from existing lat/lng columns
        DB::statement('UPDATE units SET geom = ST_SetSRID(ST_MakePoint(lng::double precision, lat::double precision), 4326) WHERE lat IS NOT NULL AND lng IS NOT NULL;');

        // Create spatial index (GiST) for fast spatial queries
        DB::statement('CREATE INDEX idx_units_geom ON units USING GIST(geom);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_units_geom;');
        
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('geom');
        });
    }
};
