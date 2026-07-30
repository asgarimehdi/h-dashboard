<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite index on (user_id, is_read, created_at) to cover:
     * 1. Notification bell query: WHERE user_id = ? ORDER BY created_at DESC LIMIT 15
     * 2. Unread count query: WHERE user_id = ? AND is_read = false
     * 3. Cleanup queries: WHERE created_at < ?
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read', 'created_at'], 'notifications_user_read_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_read_created_idx');
        });
    }
};
