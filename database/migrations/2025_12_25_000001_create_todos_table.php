<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->timestamps();

            $table->foreign('unit_id', 'todos_unit_fk')
                ->references('id')->on('units')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
