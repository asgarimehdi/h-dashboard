<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign('activity_logs_user_id_foreign');
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id', 'activity_logs_user_id_foreign')
                ->references('id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        $nullRows = DB::table('activity_logs')->whereNull('user_id')->count();

        if ($nullRows > 0) {
            throw new RuntimeException(
                "Rollback aborted: activity_logs.user_id has {$nullRows} NULL row(s) — "
                .'logs of actions whose actor was removed (user delete / backup restore). '
                .'Rolling back would either drop that audit history '
                .'(`DELETE FROM activity_logs WHERE user_id IS NULL`) or fail; '
                .'decide explicitly, then retry.'
            );
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign('activity_logs_user_id_foreign');
            $table->unsignedBigInteger('user_id')->change();
            $table->foreign('user_id', 'activity_logs_user_id_foreign')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }
};
