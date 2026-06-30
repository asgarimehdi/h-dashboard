<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->unsignedBigInteger('unit_type_id')->nullable();
            $table->double('lat')->nullable();
            $table->double('lng')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('boundary_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('can_receive_tickets')->default(false);
            $table->timestamps();

            $table->foreign('unit_type_id', 'units_type_fk')
                ->references('id')->on('unit_types');
            $table->foreign('region_id', 'units_region_fk')
                ->references('id')->on('regions')
                ->onDelete('restrict')
                ->onUpdate('cascade');
            $table->foreign('parent_id', 'units_parent_fk')
                ->references('id')->on('units')
                ->onDelete('restrict')
                ->onUpdate('cascade');
            $table->foreign('boundary_id', 'units_boundary_fk')
                ->references('id')->on('boundaries')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
