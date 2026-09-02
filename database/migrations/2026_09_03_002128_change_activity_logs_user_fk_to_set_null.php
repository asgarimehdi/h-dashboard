<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign('activity_logs_user_id_foreign');
            $table->nullableBigInteger('user_id')->change();
            $table->foreign('user_id', 'activity_logs_user_id_foreign')
                ->references('id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign('activity_logs_user_id_foreign');
            $table->unsignedBigInteger('user_id')->change();
            $table->foreign('user_id', 'activity_logs_user_id_foreign')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }
};
