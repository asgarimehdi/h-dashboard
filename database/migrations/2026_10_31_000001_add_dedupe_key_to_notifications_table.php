<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable();
            $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_user_dedupe_key_unique');
            $table->dropColumn('dedupe_key');
        });
    }
};
