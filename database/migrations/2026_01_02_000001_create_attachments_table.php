<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('file_path');
            $table->string('file_name');
            $table->integer('file_size');
            $table->unsignedBigInteger('activity_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('ticket_id', 'att_ticket_fk')
                ->references('id')->on('tickets')
                ->onDelete('cascade');
            $table->foreign('user_id', 'att_user_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');
            $table->foreign('activity_id', 'att_activity_fk')
                ->references('id')->on('task_activities')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
