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
        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ticket_comments')->cascadeOnDelete();
            $table->longText('body');
            $table->longText('body_html')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('system_event')->nullable(); // assigned, accepted, completed, reopened, forwarded
            $table->softDeletes();
            $table->timestamps();

            $table->index(['ticket_id', 'parent_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
    }
};
