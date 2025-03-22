<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unit_type_relationships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_unit_type_id');
            $table->unsignedBigInteger('allowed_parent_unit_type_id');
            $table->timestamps();

            $table->foreign('child_unit_type_id')
                ->references('id')->on('unit_types')
                ->onDelete('cascade');

            $table->foreign('allowed_parent_unit_type_id')
                ->references('id')->on('unit_types')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_type_relationships');
    }
};