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
        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->geometry('boundary')->srid(4326);
            $table->timestamps();
        });
     //   DB::statement('ALTER TABLE boundaries ADD boundary MULTIPOLYGON NOT NULL SRID 4326');
        DB::statement('ALTER TABLE boundaries MODIFY boundary MULTIPOLYGON;');
//        DB::statement("ALTER TABLE boundaries ADD COLUMN boundary GEOMETRY(MULTIPOLYGON, 4326) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boundaries');
    }
};
