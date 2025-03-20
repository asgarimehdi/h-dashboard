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
            $table->string('n_code', 10)->unique()->index(); // code melli
            $table->string('f_name');
            $table->string('l_name');
            $table->foreignId('t_id'); //tahsilat
            $table->foreignId('e_id'); //estekhdam
            $table->foreignId('s_id'); //semat
            $table->foreignId('r_id'); //radif sazmani
            $table->foreignId('u_id'); //unit = vahed
            $table->timestamps();

            $table->foreign('e_id')->references('id')->on('estekhdams');
            $table->foreign('t_id')->references('id')->on('tahsils');
            $table->foreign('s_id')->references('id')->on('semats');
            $table->foreign('r_id')->references('id')->on('radifs');
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
