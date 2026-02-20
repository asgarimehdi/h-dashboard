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
       Schema::create('task_activities', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained(); // انجام دهنده عملیات
    
    $table->string('action'); // created, forwarded, rejected, finished
    $table->text('description')->nullable();

    // ارجاع به کجا یا چه کسی؟
    $table->foreignId('to_unit_id')->nullable()->constrained('units');
    $table->foreignId('to_user_id')->nullable()->constrained('users');

    $table->boolean('is_internal')->default(false);
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
