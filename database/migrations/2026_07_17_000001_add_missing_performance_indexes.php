<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('unit_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->index('updated_at');
            $table->index('current_assignee_id');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('type');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['updated_at']);
            $table->dropIndex(['unit_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropIndex(['current_assignee_id']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['type']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};