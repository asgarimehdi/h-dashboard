<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            // 'none' | 'daily' | 'weekly' | 'monthly'
            $table->string('recurrence_rule')->default('none')->after('is_completed');
            $table->unsignedInteger('recurrence_interval')->default(1)->after('recurrence_rule');
            $table->timestamp('last_generated_at')->nullable()->after('recurrence_interval');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn(['recurrence_rule', 'recurrence_interval', 'last_generated_at']);
        });
    }
};
