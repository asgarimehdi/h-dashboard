<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_code')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('unit_id')->index();
            $table->string('subject');
            $table->text('content');
            $table->enum('priority', ['low', 'normal', 'urgent'])->default('normal');
            $table->string('status')->default('created')->index();
            $table->unsignedBigInteger('current_assignee_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');

            $table->foreign('user_id', 'tickets_user_fk')
                ->references('id')->on('users')
                ->onDelete('cascade');
            $table->foreign('unit_id', 'tickets_unit_fk')
                ->references('id')->on('units')
                ->onDelete('restrict');
            $table->foreign('current_assignee_id', 'tickets_assignee_fk')
                ->references('id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
