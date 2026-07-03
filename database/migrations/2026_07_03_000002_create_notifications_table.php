<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type'); // ticket_created, ticket_forwarded, ticket_completed, todo_assigned
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('icon')->default('o-bell');
            $table->string('color')->default('text-info');
            $table->string('url')->nullable(); // لینک مقصد
            $table->json('data')->nullable(); // داده‌های اضافی
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
