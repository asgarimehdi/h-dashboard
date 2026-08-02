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
        // Check if table already exists (to avoid conflicts in testing with in-memory SQLite)
        if (!Schema::hasTable('hardware_histories')) {
            Schema::create('hardware_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hardware_id')->constrained('hardwares')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action'); // created, updated, deleted, bulk_mark, bulk_delete
                $table->json('changes')->nullable(); // field-level diff: [{field, old, new}]
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamps();

                $table->index(['hardware_id', 'created_at']);
                $table->index('user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hardware_histories');
    }
};
