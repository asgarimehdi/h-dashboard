<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['responsible', 'staff'])->default('staff');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'unit_id']);
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_units');
    }
};
