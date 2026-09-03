<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // task_id already has FK but no explicit index in MySQL (FK doesn't auto-index)
            // Add composite index for aggregate queries: task_id + status
            $table->index(['task_id', 'status'], 'tickets_task_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_task_status_idx');
        });
    }
};
