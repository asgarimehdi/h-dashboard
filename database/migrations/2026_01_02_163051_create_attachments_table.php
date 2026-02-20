<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::create('attachments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained();
    $table->string('file_path');
    $table->string('file_name');
    $table->integer('file_size');
    $table->foreignId('activity_id')->nullable()->constrained('task_activities')->cascadeOnDelete();
    $table->timestamps();
});
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
