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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            // افزودن ستون region_id 
            $table->unsignedBigInteger('region_id')->nullable();
            // برای ساختار سلسله مراتب
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name')->unique();;
            // افزودن ستون unit_type_id
            $table->unsignedBigInteger('unit_type_id')->nullable();
            $table->foreign('unit_type_id')->references('id')->on('unit_types');

            $table->text('description')->nullable();
            $table->timestamps();


            $table->foreign('region_id')->references('id')->on('regions')
                ->onDelete('restrict')
                ->onUpdate('cascade');
            $table->foreignId('boundary_id')->nullable()->constrained('boundaries')->cascadeOnDelete();
            // تعریف کلید خارجی به استان‌ها
            // $table->foreign('province_id')->references('id')->on('provinces')
            //     ->onDelete('restrict')
            //     ->onUpdate('cascade');
            // // تعریف کلید خارجی به شهرستان‌ها
            // $table->foreign('county_id')->references('id')->on('counties')
            //     ->onDelete('restrict')
            //     ->onUpdate('cascade');
            // تعریف کلید خارجی برای سلسله مراتب واحدها
            $table->foreign('parent_id')->references('id')->on('units')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};