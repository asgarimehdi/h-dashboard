<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add spatial index (SPATIAL index / GiST equivalent for MySQL/MariaDB) on boundaries.boundary
        // This enables fast spatial queries like ST_Contains, ST_Intersects, ST_Distance, etc.
        // SQLite doesn't support spatial indexes natively, so skip on SQLite (used for testing)
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            Schema::table('boundaries', function (Blueprint $table) {
                $table->spatialIndex('boundary', 'boundaries_boundary_spatial_index');
            });
        }

        // Add composite B-tree index on units.lat and units.lng for coordinate-based queries
        // This speeds up bounding box queries and point-in-radius searches
        Schema::table('units', function (Blueprint $table) {
            $table->index(['lat', 'lng'], 'units_lat_lng_index');
        });

        // Add index on units.boundary_id for faster joins with boundaries table
        // (already exists as FK index but ensure it's optimized)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            Schema::table('boundaries', function (Blueprint $table) {
                $table->dropSpatialIndex('boundaries_boundary_spatial_index');
            });
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_lat_lng_index');
        });
    }
};
