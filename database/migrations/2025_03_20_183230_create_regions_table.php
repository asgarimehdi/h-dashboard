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
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable(); // برای رابطه سلسله‌مراتبی (شهرستان‌ها به استان‌ها)
            $table->string('type'); // برای مشخص کردن نوع: province یا county
            $table->foreignId('boundary_id')->nullable()->constrained('boundaries')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('regions')->onDelete('restrict')->onUpdate('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};