<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_logs', function (Blueprint $table) {
            $table->index('latitude', 'idx_location_logs_lat');
            $table->index('longitude', 'idx_location_logs_lng');
        });
    }

    public function down(): void
    {
        Schema::table('location_logs', function (Blueprint $table) {
            $table->dropIndex('idx_location_logs_lat');
            $table->dropIndex('idx_location_logs_lng');
        });
    }
};
