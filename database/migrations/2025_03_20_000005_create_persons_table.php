<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            $table->foreignId('u_id')->index();
            $table->timestamps();

            $table->foreign('e_id', 'persons_e_id_fk')
                ->references('id')->on('estekhdams')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreign('t_id', 'persons_t_id_fk')
                ->references('id')->on('tahsils')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreign('s_id', 'persons_s_id_fk')
                ->references('id')->on('semats')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreign('r_id', 'persons_r_id_fk')
                ->references('id')->on('radifs')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
