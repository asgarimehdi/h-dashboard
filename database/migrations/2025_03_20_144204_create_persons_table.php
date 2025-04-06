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
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->string('n_code', 10)->unique()->index();
            $table->string('f_name');
            $table->string('l_name');
            $table->foreignId('t_id');
            $table->foreignId('e_id');
            $table->foreignId('s_id');
            $table->foreignId('r_id');
            $table->foreignId('u_id');
            $table->timestamps();

            $table->foreign('e_id')
                ->references('id')->on('estekhdams')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreign('t_id')
                ->references('id')->on('tahsils')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreign('s_id')
                ->references('id')->on('semats')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreign('r_id')
                ->references('id')->on('radifs')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
