<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-unit yearly population (#1240, §3.5; decision 10).
     *
     * Only leaf-level units own a row. Higher layers never store a row: the
     * subtree sum is always the source of truth, and reading a population
     * from an upper-layer row is a bug.
     */
    public function up(): void
    {
        Schema::create('unit_populations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->smallInteger('year');
            $table->integer('population');
            $table->timestamps();

            $table->unique(['unit_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_populations');
    }
};
