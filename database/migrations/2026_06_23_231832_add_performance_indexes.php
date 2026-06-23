<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index('status');
            $table->index('unit_id');
            $table->index('created_at');
            $table->index('user_id');
        });

        Schema::table('persons', function (Blueprint $table): void {
            $table->index('u_id');
        });

        Schema::table('task_activities', function (Blueprint $table): void {
            $table->index('ticket_id');
            $table->index('user_id');
        });

        Schema::table('attachments', function (Blueprint $table): void {
            $table->index('ticket_id');
            $table->index('activity_id');
        });

        Schema::table('location_logs', function (Blueprint $table): void {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['unit_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('persons', function (Blueprint $table): void {
            $table->dropIndex(['u_id']);
        });

        Schema::table('task_activities', function (Blueprint $table): void {
            $table->dropIndex(['ticket_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex(['ticket_id']);
            $table->dropIndex(['activity_id']);
        });

        Schema::table('location_logs', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
        });
    }
};
