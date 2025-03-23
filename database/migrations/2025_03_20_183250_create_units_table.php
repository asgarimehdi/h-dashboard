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
            // فیلد province_id برای واحدهایی که به سطح استان تعلق دارند
            $table->unsignedBigInteger('province_id')->nullable();
            // فیلد county_id برای واحدهایی که به شهرستان تعلق دارند
            $table->unsignedBigInteger('county_id')->nullable();
            // برای ساختار سلسله مراتب
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name')->unique();;
            // افزودن ستون unit_type_id
            $table->unsignedBigInteger('unit_type_id')->nullable();
            $table->foreign('unit_type_id')->references('id')->on('unit_types');

            $table->text('description')->nullable();
            $table->timestamps();

            // تعریف کلید خارجی به استان‌ها
            $table->foreign('province_id')->references('id')->on('provinces');
            // تعریف کلید خارجی به شهرستان‌ها
            $table->foreign('county_id')->references('id')->on('counties');
            // تعریف کلید خارجی برای سلسله مراتب واحدها
            $table->foreign('parent_id')->references('id')->on('units');
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
