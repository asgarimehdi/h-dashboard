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
       Schema::create('tickets', function (Blueprint $table) {
    $table->id();
    $table->string('ticket_code')->unique();
    $table->foreignId('user_id')->constrained(); // ایجاد کننده
    $table->foreignId('unit_id')->constrained(); // واحد مقصد اولیه
    $table->string('subject');
    $table->text('content');
    $table->enum('priority', ['low', 'normal', 'urgent'])->default('normal');
    
    // وضعیت فعلی (برای سرعت در نمایش لیست‌ها)
    $table->string('status')->default('created'); 
    
    $table->foreignId('current_assignee_id')->nullable()->constrained('users');

    $table->timestamp('accepted_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
