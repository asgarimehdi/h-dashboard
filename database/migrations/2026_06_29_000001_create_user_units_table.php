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
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('unit_id')->index();
            $table->enum('role', ['responsible', 'staff'])->default('staff');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'unit_id']);

            $table->foreign('user_id', 'uu_user_fk')
                ->references('id')->on('users')
                ->onDelete('cascade');
            $table->foreign('unit_id', 'uu_unit_fk')
                ->references('id')->on('units')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_units');
    }
};
