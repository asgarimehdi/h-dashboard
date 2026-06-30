<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('type');
            $table->unsignedBigInteger('boundary_id')->nullable();
            $table->timestamps();

            $table->foreign('boundary_id', 'regions_boundary_fk')
                ->references('id')->on('boundaries')
                ->onDelete('cascade');
            $table->foreign('parent_id', 'regions_parent_fk')
                ->references('id')->on('regions')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
