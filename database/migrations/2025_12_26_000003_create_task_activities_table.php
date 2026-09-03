<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('action');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('to_unit_id')->nullable();
            $table->unsignedBigInteger('to_user_id')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->foreign('ticket_id', 'ta_ticket_fk')
                ->references('id')->on('tickets')
                ->onDelete('cascade');
            $table->foreign('user_id', 'ta_user_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');
            $table->foreign('to_unit_id', 'ta_tounit_fk')
                ->references('id')->on('units')
                ->onDelete('set null');
            $table->foreign('to_user_id', 'ta_touser_fk')
                ->references('id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
